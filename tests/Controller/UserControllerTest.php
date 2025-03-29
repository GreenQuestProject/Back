<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase{

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private KernelBrowser $client;
    protected function setUp(): void
    {
        $this->client = UserControllerTest::createClient();
        $this->entityManager = UserControllerTest::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = UserControllerTest::getContainer()->get(UserPasswordHasherInterface::class);

    }

    private function createUser(string $email, string $password, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(explode('@', $email)[0]);
        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function getJwtToken(string $email, string $password): string
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => $email,
            'password' => $password
        ]));

        $response = json_decode($this->client->getResponse()->getContent(), true);
        return $response['token'] ?? '';
    }

    private function deleteUser(User $user): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testCreateUserSuccessfully(): void
    {
        $userRepository = UserControllerTest::getContainer()->get(UserRepository::class);

        $userData = [
            'username' => 'testuser',
            'email' => 'testuser@example.com',
            'password' => 'password123'
        ];

        $this->client->request(
            'POST',
            '/api/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $user = $userRepository->findOneBy(['email' => 'testuser@example.com']);
        $this->assertNotNull($user, 'L\'utilisateur a bien été enregistré.');

        $this->assertNotEquals('password123', $user->getPassword(), 'Le mot de passe est bien hashé.');

        $this->deleteUser($user);
    }

    public function testCreateUserWithInvalidData(): void
    {
        $invalidData = [
            'username' => 'invalidUser'
        ];

        $this->client->request(
            'POST',
            '/api/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($invalidData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUpdateUser(): void
    {
        $user = $this->createUser('old@example.com', 'test');
        $userId = $user->getId();
        $jwtToken = $this->getJwtToken('old@example.com', 'test');

        $this->client->request('PUT', "/api/user/$userId", [], [],
            ['HTTP_AUTHORIZATION' => "Bearer $jwtToken"],
            json_encode(['email' => 'new@example.com'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $updatedUser = $this->entityManager->getRepository(User::class)->find($userId);
        $this->assertSame("new@example.com", $updatedUser->getEmail());

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => "new@example.com"]);

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testDeleteUserAsAdmin(): void
    {
        $userToDelete = $this->createUser('todelete@example.com', 'test');
        $userToDeleteId = $userToDelete->getId();

        $adminUser = $this->createUser('admin@example.com', 'admin', ["ROLE_ADMIN"]);
        $jwtToken = $this->getJwtToken('admin@example.com', 'admin');

        $this->client->request('DELETE', "/api/user/$userToDeleteId", [],[],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $deletedUser = $this->entityManager->getRepository(User::class)->find($userToDeleteId);
        $this->assertNull($deletedUser);

        $this->deleteUser($adminUser);
    }

    public function testDeleteUserAsNonAdmin(): void
    {
        $userToDelete = $this->createUser('todelete@example.com', 'test');
        $userToDeleteId = $userToDelete->getId();

        $normalUser = $this->createUser('nonadminuser@example.com', 'test');
        $jwtToken = $this->getJwtToken('nonadminuser@example.com', 'test');

        $this->client->request(
            'DELETE',
            "/api/user/$userToDeleteId",
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $existingUser = $this->entityManager->getRepository(User::class)->find($userToDeleteId);
        $this->assertNotNull($existingUser);

        $this->deleteUser($userToDelete);
        $this->deleteUser($normalUser);
    }

    public function testGetAllUsersAsAdmin(): void
    {
        $adminUser = $this->createUser('admin@example.com', 'admin', ["ROLE_ADMIN"]);
        $jwtToken = $this->getJwtToken('admin@example.com', 'admin');

        // Effectuer la requête GET sur la route '/api/user'
        $this->client->request('GET', '/api/user',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );

        // Vérifier que la réponse a le statut 200 (OK)
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        // Vérifier que le contenu de la réponse est au format JSON
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        // Décoder la réponse JSON pour vérifier le contenu
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Vérifier que la réponse contient une liste d'utilisateurs
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);  // Vérifie que la liste n'est pas vide

        $this->deleteUser($adminUser);
    }

    public function testGetUser(): void
    {
        $user = $this->createUser('user@example.com', 'test');
        $userId = $user->getId();
        $jwtToken = $this->getJwtToken('user@example.com', 'test');

        // Effectuer la requête GET sur la route '/api/user/{idUser}'
        $this->client->request('GET', "/api/user/$userId",
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]);

        // Vérifier que la réponse a le statut 200 (OK)
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        // Vérifier que la réponse est au format JSON
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        // Décoder la réponse JSON pour vérifier le contenu
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Vérifier que l'utilisateur est retourné et que son email est correct
        $this->assertArrayHasKey('email', $responseData);
        $this->assertEquals('user@example.com', $responseData['email']);
        $this->assertArrayHasKey('username', $responseData);
        $this->assertEquals('user', $responseData['username']);

        $this->deleteUser($user);
    }

    public function testGetNonExistentUser(): void
    {
        $user = $this->createUser('user@example.com', 'test');
        $jwtToken = $this->getJwtToken('user@example.com', 'test');

        // Essayer de récupérer un utilisateur qui n'existe pas
        $this->client->request('GET', '/api/user/99999',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]);  // Remplacer par un ID qui n'existe pas

        // Vérifier que la réponse a le statut 404 (Not Found)
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->deleteUser($user);
    }

    public function testUserCanAccessOwnProfile(): void
    {
        $user = $this->createUser('user@example.com', 'password');

        $jwtToken = $this->getJwtToken('user@example.com', 'password');
        $this->client->request('GET', "/api/user/{$user->getId()}", [], [], ['HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJson($this->client->getResponse()->getContent());
        $this->deleteUser($user);
    }

    public function testUserCannotAccessAnotherUserProfile(): void
    {
        $user1 = $this->createUser('user1@example.com', 'password');
        $user2 = $this->createUser('user2@example.com', 'password');

        $jwtToken = $this->getJwtToken('user1@example.com', 'password');
        $this->client->request('GET', "/api/user/{$user2->getId()}", [], [], ['HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseStatusCodeSame(403);

        $this->deleteUser($user1);
        $this->deleteUser($user2);
    }

    public function testAdminCanAccessAnotherUserProfile(): void
    {
        $user = $this->createUser('user@example.com', 'password');
        $admin = $this->createUser('admin@example.com', 'password', ['ROLE_ADMIN']);

        $jwtToken = $this->getJwtToken('admin@example.com', 'password');
        $this->client->request('GET', "/api/user/{$user->getId()}", [], [], ['HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJson($this->client->getResponse()->getContent());

        $this->deleteUser($user);
        $this->deleteUser($admin);
    }

    public function testUnauthenticatedUserCannotAccessProfile(): void
    {
        $user = $this->createUser('user@example.com', 'password');

        $this->client->request('GET', "/api/user/{$user->getId()}");

        $this->assertResponseStatusCodeSame(401);
        $this->deleteUser($user);
    }
}

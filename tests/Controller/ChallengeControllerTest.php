<?php

namespace App\Tests\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Repository\ChallengeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ChallengeControllerTest extends WebTestCase{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private UserPasswordHasherInterface $passwordHasher;
    protected function setUp(): void
    {
        //self::bootKernel(); // Lance le kernel Symfony
        $this->client = ChallengeControllerTest::createClient();
        $this->entityManager = ChallengeControllerTest::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = ChallengeControllerTest::getContainer()->get(UserPasswordHasherInterface::class);

        // Nettoyage si besoin (sécurité en cas de test planté précédemment)
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Challenge')->execute();

        // Créer un user
      $user = (new User())
          ->setUsername('user')
          ->setEmail('user@user')
          ->setRoles(['ROLE_USER']);
      $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
      $this->entityManager->persist($user);

      // Créer un admin
      $admin = (new User())
          ->setUsername('admin')
          ->setEmail('admin@admin')
          ->setRoles(['ROLE_ADMIN']);
          $admin->setPassword($this->passwordHasher->hashPassword($admin, 'password'));
      $this->entityManager->persist($admin);


        $this->entityManager->flush();
    }

    private function getJwtToken(string $username, string $password): string
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'username' => $username,
            'password' => $password
        ]));
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        return $response['token'] ?? '';
    }

    private function createChallenge(string $name): Challenge
    {
        $challenge = new Challenge();
        $challenge->setName($name);

        $this->entityManager->persist($challenge);
        $this->entityManager->flush();

        return $challenge;
    }

    private function deleteChallenge(Challenge $challenge): void
    {
        $challenge = $this->entityManager->getRepository(Challenge::class)->findOneBy(['name' => $challenge->getName()]);

        $this->entityManager->remove($challenge);
        $this->entityManager->flush();
    }


    public function testCreateChallengeSuccessfully(): void
    {
        $challengeRepository = ChallengeControllerTest::getContainer()->get(ChallengeRepository::class);

        $challengeData = [
            'name' => 'test',
            'category' => "none"
        ];

        $jwtToken = $this->getJwtToken('admin', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request(
            'POST',
            '/api/challenge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
            json_encode($challengeData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $challenge = $challengeRepository->findOneBy(['name' => 'test']);
        $this->assertNotNull($challenge, 'Le challenge a bien été enregistré.');

        $this->deleteChallenge($challenge);
    }

    public function testCreateChallengeAsNonAdmin(): void
    {
        $challengeRepository = ChallengeControllerTest::getContainer()->get(ChallengeRepository::class);

        $challengeData = [
            'name' => 'test',
        ];

        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request(
            'POST',
            '/api/challenge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
            json_encode($challengeData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCreateChallengeWithInvalidData(): void
    {
        $invalidData = [
            'name' => ''
        ];

        $jwtToken = $this->getJwtToken('admin', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request(
            'POST',
            '/api/challenge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
            json_encode($invalidData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreateChallengeWithNoData(): void
    {
        $jwtToken = $this->getJwtToken('admin', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request(
            'POST',
            '/api/challenge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }


    public function testUpdateChallengeSuccessfully(): void
    {
        $challenge = $this->createChallenge('test');
        $challengeId = $challenge->getId();

        $jwtToken = $this->getJwtToken('admin', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('PUT', "/api/challenge/$challengeId", [], [],
            ['HTTP_AUTHORIZATION' => "Bearer $jwtToken"],
            json_encode(['description' => 'A cool description'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $updatedChallenge = $this->entityManager->getRepository(Challenge::class)->find($challengeId);
        $this->assertSame("A cool description", $updatedChallenge->getDescription());

        $this->deleteChallenge($challenge);
    }

    public function testUpdateChallengeAsNonAdmin(): void
    {
        $challenge = $this->createChallenge('test');
        $challengeId = $challenge->getId();

        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('PUT', "/api/challenge/$challengeId", [], [],
            ['HTTP_AUTHORIZATION' => "Bearer $jwtToken"],
            json_encode(['description' => 'A cool description'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $notUpdatedChallenge = $this->entityManager->getRepository(Challenge::class)->find($challengeId);
        $this->assertSame(null, $notUpdatedChallenge->getDescription());

        $this->deleteChallenge($challenge);
    }

    public function testDeleteChallengeSuccessfully(): void
    {
        $challenge = $this->createChallenge('test');
        $challeengeId = $challenge->getId();

        $jwtToken = $this->getJwtToken('admin', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('DELETE', "/api/challenge/$challeengeId", [], [],
            ['HTTP_AUTHORIZATION' => "Bearer $jwtToken"],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $deletedChallenge = $this->entityManager->getRepository(Challenge::class)->find($challeengeId);
        $this->assertNull($deletedChallenge);
    }

    public function testDeleteNonExistentChallenge(): void
    {
        $jwtToken = $this->getJwtToken('admin', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('DELETE', "/api/challenge/99999", [], [],
            ['HTTP_AUTHORIZATION' => "Bearer $jwtToken"],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteChallengeAsNonAdmin(): void
    {
        $challenge = $this->createChallenge('test');
        $challeengeId = $challenge->getId();

        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('DELETE', "/api/challenge/$challeengeId", [], [],
            ['HTTP_AUTHORIZATION' => "Bearer $jwtToken"],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $existingChallenge = $this->entityManager->getRepository(Challenge::class)->find($challeengeId);
        $this->assertNotNull($existingChallenge);
        $this->deleteChallenge($challenge);
    }

    public function testGetAllChallenge(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('GET', '/api/challenge',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
        $this->assertEmpty($responseData);
    }

    public function testGetChallenge(): void
    {
        $challenge = $this->createChallenge('test');
        $challengeId = $challenge->getId();
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('GET', "/api/challenge/$challengeId",
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('name', $responseData);
        $this->assertEquals('test', $responseData['name']);
        $this->assertArrayHasKey('description', $responseData);
        $this->assertEquals(null, $responseData['description']);

        $this->deleteChallenge($challenge);
    }

    public function testGetNonExistentChallenge(): void
    {
        $challenge = $this->createChallenge('test');
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('GET', '/api/challenge/99999',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->deleteChallenge($challenge);
    }

}

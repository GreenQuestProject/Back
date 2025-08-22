<?php

namespace App\Tests\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProgressionControllerTest extends WebTestCase{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private UserPasswordHasherInterface $passwordHasher;
    private User $user;
    private Challenge $challenge;
    protected function setUp(): void
    {
        //self::bootKernel(); // Lance le kernel Symfony
        $this->client = ChallengeControllerTest::createClient();
        $this->entityManager = ChallengeControllerTest::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = ChallengeControllerTest::getContainer()->get(UserPasswordHasherInterface::class);

        // Nettoyage si besoin (sécurité en cas de test planté précédemment)
        $this->entityManager->createQuery('DELETE FROM App\Entity\Progression')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Challenge')->execute();

        // Créer un user
        $this->user = (new User())
            ->setUsername('user')
            ->setEmail('user@user')
            ->setRoles(['ROLE_USER']);
        $this->user->setPassword($this->passwordHasher->hashPassword($this->user, 'password'));
        $this->entityManager->persist($this->user);

        // Créer un challenge
        $this->challenge = (new Challenge())
            ->setName('test')
            ->setCategory(ChallengeCategory::NONE)
            ->setDescription('A cool description');
        $this->entityManager->persist($this->challenge);

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

    private function createProgression(User $user): Progression
    {
        $progression = new Progression();
        $progression->setUser($user)
        ->setChallenge($this->challenge)
        ->setStatus(ChallengeStatus::PENDING)->setStartedAt(new \DateTime());

        $this->entityManager->persist($progression);
        $this->entityManager->flush();

        return $progression;
    }

    private function deleteProgression(Progression $progression): void
    {
        $progression = $this->entityManager->getRepository(Progression::class)->find($progression);

        $this->entityManager->remove($progression);
        $this->entityManager->flush();
    }

    public function testStartChallengeUnauthenticated(): void
    {
        $this->client->request('POST', '/api/progression/start/'. $this->challenge->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testStartChallengeUserNotFound(): void
    {
        // Utilisateur fictif jamais persisté
        $ghostUser = new User();
        $ghostUser->setUsername('ghost');
        $ghostUser->setPassword('fakepassword');
        $this->client->loginUser($ghostUser);

        $this->client->request('POST', '/api/progression/start/' . $this->challenge->getId());

        $this->assertResponseStatusCodeSame(401);
        $this->assertStringContainsString('Utilisateur introuvable', $this->client->getResponse()->getContent());
    }

    public function testStartChallenge(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/start/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
            ]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Défi commencé avec succès', $response['message']);
    }

    public function testStartAlreadyStartedChallenge(): void
    {
        $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/start/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
            ]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Défi déjà commencé', $response['message']);
    }

    public function testRemoveChallenge(): void
    {
        $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'DELETE',
            '/api/progression/remove/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Défi supprimé de vos progressions', $response['message']);
    }

    public function testRemoveChallengeNotFound(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request('DELETE', '/api/progression/remove/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRemoveChallengeProgressionNotFound(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request('DELETE', '/api/progression/remove/999999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
    
    public function testValidateChallenge(): void
    {
        $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/validate/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Défi validé avec succès', $response['message']);
    }

    public function testValidateChallengeNotFound(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/validate/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateStatusSuccessfully(): void
    {
        $progression = $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request('POST', '/api/progression/status/' . $progression->getId(),
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode(['status' => 'completed'])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Statut de la progression mis à jour avec succès', $response['message']);

        // Re-fetch entity to ensure it's managed
        $progression = $this->entityManager->getRepository(Progression::class)->find($progression->getId());

        $this->assertEquals(ChallengeStatus::COMPLETED, $progression->getStatus());
        $this->assertNotNull($progression->getCompletedAt());
    }


    public function testProgressionNotFound(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request('POST', '/api/progression/status/999999',
            [
            'CONTENT_TYPE' => 'application/json',
            ],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode(['status' => 'completed']));

        $this->assertResponseStatusCodeSame(404);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Progression non trouvée ou accès refusé', $response['message']);

    }

    public function testProgressionBelongsToAnotherUser(): void
    {
        $user2 = (new User())
            ->setUsername('user2')
            ->setEmail('user2@user')
            ->setRoles(['ROLE_USER']);
        $user2->setPassword($this->passwordHasher->hashPassword($user2, 'password'));
        $this->entityManager->persist($user2);

        $progression = $this->createProgression($user2);

        $token = $this->getJwtToken('user', 'password');

        $this->client->request('POST', '/api/progression/status/' . $progression->getId(), [
            'CONTENT_TYPE' => 'application/json',
        ],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode(['status' => 'completed']));

        $this->assertResponseStatusCodeSame(404);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Progression non trouvée ou accès refusé', $response['message']);

    }


    public function testMissingStatusField(): void
    {
        $progression = $this->createProgression($this->user);

        $token = $this->getJwtToken('user', 'password');

        $this->client->request('POST', '/api/progression/status/' . $progression->getId(), [
            'CONTENT_TYPE' => 'application/json',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Le statut est obligatoire', $response['message']);

    }

    public function testInvalidStatus(): void
    {
        $progression = $this->createProgression($this->user);

        $token = $this->getJwtToken('user', 'password');

        $this->client->request('POST', '/api/progression/status/' . $progression->getId(), [
            'CONTENT_TYPE' => 'application/json',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['status' => 'invalid_status']));

        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Statut invalide', $response['message']);

    }

    public function testListUserProgression(): void
    {
        $progression = $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'GET',
            '/api/progression',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertEquals($this->challenge->getId(), $data[0]['challenge_id']);
    }

    public function testListProgressionWithFilter(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request('GET', '/api/progression?status=COMPLETED&category=ecologique',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');
    }



}

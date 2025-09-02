<?php

namespace App\Tests\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProgressionControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private UserPasswordHasherInterface $passwordHasher;
    private User $user;
    private Challenge $challenge;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

        // Nettoyage
        $this->entityManager->createQuery('DELETE FROM App\Entity\Progression p')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Challenge c')->execute();

        // User
        $this->user = (new User())
            ->setUsername('user')
            ->setEmail('user@user')
            ->setRoles(['ROLE_USER']);
        $this->user->setPassword($this->passwordHasher->hashPassword($this->user, 'password'));
        $this->entityManager->persist($this->user);

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

    private function createProgression(User $user, ?Challenge $challenge = null, ChallengeStatus $status = ChallengeStatus::PENDING): Progression
    {
        $progression = (new Progression())
            ->setUser($user)
            ->setChallenge($challenge ?? $this->challenge)
            ->setStatus($status)
            ->setStartedAt(new \DateTimeImmutable());

        $this->entityManager->persist($progression);
        $this->entityManager->flush();

        return $progression;
    }

    public function testStartChallengeUnauthenticated(): void
    {
        $this->client->request('POST', '/api/progression/start/' . $this->challenge->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testStartChallengeUserNotFound(): void
    {
        $ghostUser = (new User())
            ->setUsername('ghost')
            ->setPassword('fakepassword');
        $this->client->loginUser($ghostUser);

        $this->client->request('POST', '/api/progression/start/' . $this->challenge->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
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

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Défi commencé avec succès', $response['message']);
    }

    public function testStartAlreadyStartedChallenge(): void
    {
        $this->createProgression($this->user, $this->challenge, ChallengeStatus::IN_PROGRESS);

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

        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Vous avez déjà ce défi en cours. Terminez-le avant d’en recommencer un.', $response['message']);
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

        $this->client->request(
            'DELETE',
            '/api/progression/remove/' . $this->challenge->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRemoveChallengeProgressionNotFound(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'DELETE',
            '/api/progression/remove/999999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]
        );

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

        $this->client->request(
            'POST',
            '/api/progression/status/' . $progression->getId(),
            ['CONTENT_TYPE' => 'application/json'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['status' => 'completed'])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Statut de la progression mis à jour avec succès', $response['message']);

        $progression = $this->entityManager->getRepository(Progression::class)->find($progression->getId());
        $this->assertEquals(ChallengeStatus::COMPLETED, $progression->getStatus());
        $this->assertNotNull($progression->getCompletedAt());
    }

    public function testProgressionNotFound(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/status/999999',
            ['CONTENT_TYPE' => 'application/json'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['status' => 'completed'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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
        $this->entityManager->flush();

        $progression = $this->createProgression($user2);

        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/status/' . $progression->getId(),
            ['CONTENT_TYPE' => 'application/json'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['status' => 'COMPLETED'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Progression non trouvée ou accès refusé', $response['message']);
    }

    public function testMissingStatusField(): void
    {
        $progression = $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/status/' . $progression->getId(),
            ['CONTENT_TYPE' => 'application/json'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Le statut est obligatoire', $response['message']);
    }

    public function testInvalidStatus(): void
    {
        $progression = $this->createProgression($this->user);
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/status/' . $progression->getId(),
            ['CONTENT_TYPE' => 'application/json'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['status' => 'invalid_status'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
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
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $last = end($data);

        $expected = [
            'id'               => $progression->getId(),
            'challengeId'      => $this->challenge->getId(),
            'description'      => $this->challenge->getDescription(),
            'name'             => $this->challenge->getName(),
            'category'         => $this->challenge->getCategory()->value,
            'status'           => $progression->getStatus()->value,
            'startedAt'        => $progression->getStartedAt()?->format('Y-m-d H:i:s'),
            'completedAt'      => $progression->getCompletedAt()?->format('Y-m-d H:i:s'),
            'reminderId'       => null,
            'nextReminderUtc'  => null,
            'recurrence'       => null,
            'timezone'         => null,
        ];

        $this->assertSame($expected, $last);
    }

    public function testListProgressionWithFilter(): void
    {
        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'GET',
            '/api/progression?status=COMPLETED&type=ecologique',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');
    }

    public function testStatusInProgressGuard(): void
    {
        $existing = $this->createProgression($this->user, $this->challenge, ChallengeStatus::IN_PROGRESS);

        $other = $this->createProgression($this->user, $this->challenge, ChallengeStatus::PENDING);

        $token = $this->getJwtToken('user', 'password');

        $this->client->request(
            'POST',
            '/api/progression/status/' . $other->getId(),
            ['CONTENT_TYPE' => 'application/json'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['status' => 'in_progress'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('déjà ce défi en cours', $response['message']);

        $existing = $this->entityManager->getRepository(Progression::class)->find($existing->getId());
        $this->assertEquals(ChallengeStatus::IN_PROGRESS, $existing->getStatus());
    }
}

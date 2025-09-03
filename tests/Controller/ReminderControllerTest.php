<?php

namespace App\Tests\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\Reminder;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReminderControllerTest extends WebTestCase
{

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    private User $user;
    private User $otherUser;
    private Challenge $challenge;
    private Progression $progression;
    private string $jwt = '';

    public function testCreateReminderSuccess(): void
    {
        $scheduledLocal = '2025-01-15T10:00:00';
        $timezone = 'Europe/Paris';

        $this->client->request(
            'POST',
            '/api/reminders',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'progressionId' => $this->progression->getId(),
                'scheduledAt' => $scheduledLocal,
                'timezone' => $timezone,
                'recurrence' => 'DAILY',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $payload);

        /** @var Reminder $saved */
        $saved = $this->em->getRepository(Reminder::class)->find($payload['id']);
        $this->assertNotNull($saved);
        $this->assertSame('DAILY', $saved->getRecurrence());
        $this->assertSame('Europe/Paris', $saved->getTimezone());
        $this->assertTrue($saved->isActive());
        $this->assertSame('2025-01-15 09:00:00', $saved->getScheduledAtUtc()->format('Y-m-d H:i:s'));
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt,
        ], $extra);
    }

    public function testCreateReminderProgressionNotFoundOrForeign(): void
    {
        $foreignProg = (new Progression())
            ->setUser($this->otherUser)
            ->setChallenge($this->challenge)
            ->setStatus(ChallengeStatus::PENDING)
            ->setStartedAt(new DateTime());
        $this->em->persist($foreignProg);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/reminders',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'progressionId' => $foreignProg->getId(),
                'scheduledAt' => '2025-01-01T10:00:00',
                'timezone' => 'Europe/Paris',
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Progression not found', $resp['error'] ?? null);

        $this->client->request(
            'POST',
            '/api/reminders',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'progressionId' => 999999,
                'scheduledAt' => '2025-01-01T10:00:00',
                'timezone' => 'Europe/Paris',
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCreateReminderDuplicateReturnsExisting(): void
    {
        $existing = $this->createReminder(
            $this->progression,
            new DateTimeImmutable('2025-01-01T08:00:00+00:00'),
            'NONE',
            'Europe/Paris',
            true
        );

        $this->client->request(
            'POST',
            '/api/reminders',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'progressionId' => $this->progression->getId(),
                'scheduledAt' => '2025-01-15T10:00:00',
                'timezone' => 'Europe/Paris',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($existing->getId(), $resp['id'] ?? null);
        $this->assertSame('exists', $resp['status'] ?? null);
    }

    private function createReminder(
        Progression       $p,
        DateTimeImmutable $whenUtc,
        string            $recurrence = 'NONE',
        string            $tz = 'Europe/Paris',
        bool              $active = true
    ): Reminder
    {
        $r = (new Reminder())
            ->setProgression($p)
            ->setScheduledAtUtc($whenUtc)
            ->setRecurrence($recurrence)
            ->setTimezone($tz)
            ->setActive($active);
        $this->em->persist($r);
        $this->em->flush();
        return $r;
    }

    public function testCreateReminderInvalidTimezone(): void
    {
        $this->client->request(
            'POST',
            '/api/reminders',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'progressionId' => $this->progression->getId(),
                'scheduledAt' => '2025-01-15T10:00:00',
                'timezone' => 'Invalid/Zone',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid timezone', $resp['error'] ?? null);
    }

    public function testCreateReminderInvalidScheduledAt(): void
    {
        $this->client->request(
            'POST',
            '/api/reminders',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'progressionId' => $this->progression->getId(),
                'scheduledAt' => 'not-a-date',
                'timezone' => 'Europe/Paris',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid scheduledAt', $resp['error'] ?? null);
    }

    public function testCompleteOneShotDeactivates(): void
    {
        $when = new DateTimeImmutable('2025-01-01T09:00:00+00:00');
        $rem = $this->createReminder($this->progression, $when, 'NONE');

        $this->client->request('POST', '/api/reminders/' . $rem->getId() . '/complete', server: $this->authHeaders());

        $this->assertResponseIsSuccessful();
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($resp['ok'] ?? false);
        $rem = $this->reloadReminder($rem->getId());
        $this->assertFalse($rem->isActive());
        $this->assertEquals($when, $rem->getScheduledAtUtc());
    }

    private function reloadReminder(int $id): Reminder
    {
        $this->em->clear();
        return $this->em->getRepository(Reminder::class)->find($id);
    }

    public function testCompleteDailyMovesOneDay(): void
    {
        $when = new DateTimeImmutable('2025-01-01T09:00:00+00:00');
        $rem = $this->createReminder($this->progression, $when, 'DAILY');

        $this->client->request('POST', '/api/reminders/' . $rem->getId() . '/complete', server: $this->authHeaders());

        $this->assertResponseIsSuccessful();
        $rem = $this->reloadReminder($rem->getId());
        $this->assertTrue($rem->isActive());
        $this->assertEquals($when->add(new DateInterval('P1D')), $rem->getScheduledAtUtc());
    }

    public function testCompleteWeeklyMovesOneWeek(): void
    {
        $when = new DateTimeImmutable('2025-01-01T09:00:00+00:00');
        $rem = $this->createReminder($this->progression, $when, 'WEEKLY');

        $this->client->request('POST', '/api/reminders/' . $rem->getId() . '/complete', server: $this->authHeaders());

        $this->assertResponseIsSuccessful();
        $rem = $this->reloadReminder($rem->getId());
        $this->assertTrue($rem->isActive());
        $this->assertEquals($when->add(new DateInterval('P1W')), $rem->getScheduledAtUtc());
    }

    public function testCompleteForbiddenForOtherUser(): void
    {
        $foreignProg = (new Progression())
            ->setUser($this->otherUser)
            ->setChallenge($this->challenge)
            ->setStatus(ChallengeStatus::PENDING)
            ->setStartedAt(new DateTime());
        $this->em->persist($foreignProg);
        $this->em->flush();

        $rem = $this->createReminder($foreignProg, new DateTimeImmutable('2025-01-01T09:00:00+00:00'));

        $this->client->request('POST', '/api/reminders/' . $rem->getId() . '/complete', server: $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Forbidden', $resp['error'] ?? null);
    }

    public function testSnoozeMovesTenMinutes(): void
    {
        $when = new DateTimeImmutable('2025-01-01T09:00:00+00:00');
        $rem = $this->createReminder($this->progression, $when, 'DAILY');

        $this->client->request('POST', '/api/reminders/' . $rem->getId() . '/snooze', server: $this->authHeaders());

        $this->assertResponseIsSuccessful();
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($rem->getId(), $resp['id'] ?? null);

        $rem = $this->reloadReminder($rem->getId());
        $expected = $when->add(new DateInterval('PT10M'));
        $this->assertEquals($expected, $rem->getScheduledAtUtc());
        $this->assertSame($expected->format(DATE_ATOM), $resp['scheduledAtUtc'] ?? null);
    }

    public function testSnoozeForbiddenForOtherUser(): void
    {
        $foreignProg = (new Progression())
            ->setUser($this->otherUser)
            ->setChallenge($this->challenge)
            ->setStatus(ChallengeStatus::PENDING)
            ->setStartedAt(new DateTime());
        $this->em->persist($foreignProg);
        $this->em->flush();

        $rem = $this->createReminder($foreignProg, new DateTimeImmutable('2025-01-01T09:00:00+00:00'), 'DAILY');

        $this->client->request('POST', '/api/reminders/' . $rem->getId() . '/snooze', server: $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $resp = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Forbidden', $resp['error'] ?? null);
    }

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $c = static::getContainer();

        $this->em = $c->get(EntityManagerInterface::class);
        $this->hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\Reminder r')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Progression p')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Challenge c')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();

        $this->user = (new User())
            ->setUsername('username')
            ->setEmail('username@example.test')
            ->setRoles(['ROLE_USER']);
        $this->user->setPassword($this->hasher->hashPassword($this->user, 'password'));
        $this->em->persist($this->user);

        $this->otherUser = (new User())
            ->setUsername('other')
            ->setEmail('other@example.test')
            ->setRoles(['ROLE_USER']);
        $this->otherUser->setPassword($this->hasher->hashPassword($this->otherUser, 'password'));
        $this->em->persist($this->otherUser);

        $this->challenge = (new Challenge())
            ->setName('Défi Reminder')
            ->setDescription('Test reminder')
            ->setCategory(ChallengeCategory::NONE);
        $this->em->persist($this->challenge);

        $this->progression = (new Progression())
            ->setUser($this->user)
            ->setChallenge($this->challenge)
            ->setStatus(ChallengeStatus::PENDING)
            ->setStartedAt(new DateTime());
        $this->em->persist($this->progression);

        $this->em->flush();

        $this->jwt = $this->getJwtToken('username', 'password');
        self::assertNotSame('', $this->jwt, 'Le token JWT ne doit pas être vide');
    }

    private function getJwtToken(string $username, string $password): string
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK, 'Login JWT doit retourner 200');
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('token', $response);

        return $response['token'];
    }
}

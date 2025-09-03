<?php

namespace App\Tests\Functional;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\ProgressionEvent;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AnalyticsControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function testOverviewReturnsValidJson(): void
    {
        $admin = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'admin@example.com']);
        $token = $this->getJwtToken($admin);

        $from = (new DateTimeImmutable('-10 days'))->format('Y-m-d');
        $to = (new DateTimeImmutable('today'))->format('Y-m-d');

        $this->client->request(
            'GET',
            "/api/analytics/overview?from={$from}&to={$to}",
            server: ['HTTP_Authorization' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $json = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('completed', $json);
        $this->assertArrayHasKey('completionRate', $json);
        $this->assertArrayHasKey('medianHours', $json);
        $this->assertArrayHasKey('weekly', $json);
        $this->assertArrayHasKey('categories', $json);

        $this->assertGreaterThanOrEqual(1, $json['completed']);
        $this->assertIsArray($json['weekly']);
        $this->assertIsArray($json['categories']);

        $cats = array_map(fn($c) => $c['category'] ?? $c['name'] ?? null, $json['categories']);
        $this->assertNotEmpty(array_filter($cats));
    }

    private function getJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }

    public function testOverviewForbiddenForUserWithoutToken(): void
    {
        $this->client->request('GET', '/api/analytics/overview');
        $this->assertTrue(in_array($this->client->getResponse()->getStatusCode(), [401, 403]));
    }

    public function testFunnelReturnsWeeklySeries(): void
    {
        $admin = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'admin@example.com']);
        $token = $this->getJwtToken($admin);

        $progression = $this->entityManager->getRepository(Progression::class)
            ->findOneBy([]);

        $base = (new DateTimeImmutable('monday this week'))->setTime(12, 0);

        $mk = function (string $type, DateTimeImmutable $at) use ($progression) {
            $e = (new ProgressionEvent())
                ->setProgression($progression)
                ->setEventType($type)
                ->setOccurredAt($at);
            $this->entityManager->persist($e);
        };

        $mk('viewed', $base->modify('+0 day'));
        $mk('started', $base->modify('+1 day'));
        $mk('done', $base->modify('+2 day'));
        $mk('abandoned', $base->modify('+3 day'));

        $prev = $base->modify('-7 days');
        $mk('viewed', $prev->modify('+1 day'));
        $mk('started', $prev->modify('+2 day'));

        $this->entityManager->flush();
        $this->entityManager->clear();

        $from = $base->modify('-14 days')->format('Y-m-d');
        $to = $base->modify('+6 days')->format('Y-m-d');

        $this->client->request(
            'GET',
            "/api/analytics/funnel?from={$from}&to={$to}",
            server: ['HTTP_Authorization' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json, 'La réponse funnel doit être une liste de semaines');

        foreach ($json as $row) {
            $this->assertArrayHasKey('week', $row);
            $this->assertArrayHasKey('viewed', $row);
            $this->assertArrayHasKey('started', $row);
            $this->assertArrayHasKey('done', $row);
            $this->assertArrayHasKey('abandoned', $row);
            $this->assertIsString($row['week']);
            $this->assertIsInt($row['viewed']);
            $this->assertIsInt($row['started']);
            $this->assertIsInt($row['done']);
            $this->assertIsInt($row['abandoned']);
        }

        $hasCurrentWeek = false;
        $isoKey = $base->format('o') . '-W' . $base->format('W');
        foreach ($json as $row) {
            if ($row['week'] === $isoKey) {
                $hasCurrentWeek = true;
                $this->assertGreaterThanOrEqual(1, $row['viewed']);
                $this->assertGreaterThanOrEqual(1, $row['started']);
                $this->assertGreaterThanOrEqual(1, $row['done']);
                $this->assertGreaterThanOrEqual(1, $row['abandoned']);
                break;
            }
        }
        $this->assertTrue($hasCurrentWeek, 'La semaine courante doit apparaître dans le funnel');
    }

    public function testCohortsReturnsMatrix(): void
    {
        $admin = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'admin@example.com']);
        $token = $this->getJwtToken($admin);

        $repoUser = $this->entityManager->getRepository(User::class);
        $users = $repoUser->findAll();
        $monday = (new DateTimeImmutable('monday this week'))->setTime(10, 0);

        foreach ($users as $u) {
            if (method_exists($u, 'setCreatedAt')) {
                $u->setCreatedAt($monday);
                $this->entityManager->persist($u);
            }
        }

        $user = $repoUser->findOneBy(['email' => 'user@example.com']);
        $challenge = $this->entityManager->getRepository(Challenge::class)->findOneBy([]);
        $p = (new Progression())
            ->setUser($user)
            ->setChallenge($challenge)
            ->setStartedAt($monday->modify('+1 day'))
            ->setStatus(ChallengeStatus::COMPLETED)
            ->setCompletedAt($monday->modify('+2 days'));
        $this->entityManager->persist($p);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $from = (new DateTimeImmutable('-12 weeks'))->format('Y-m-d');
        $to = (new DateTimeImmutable('today'))->format('Y-m-d');

        $this->client->request(
            'GET',
            "/api/analytics/cohorts?from={$from}&to={$to}",
            server: ['HTTP_Authorization' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('cohorts', $json);
        $this->assertIsArray($json['cohorts']);

        foreach ($json['cohorts'] as $row) {
            $this->assertArrayHasKey('signup_week', $row);
            $this->assertIsString($row['signup_week']);
            foreach ($row as $k => $v) {
                if ($k === 'signup_week') continue;
                $this->assertMatchesRegularExpression('/^w\\d+$/', $k);
                $this->assertIsNumeric($v);
                $this->assertGreaterThanOrEqual(0, (float)$v);
                $this->assertLessThanOrEqual(100, (float)$v);
            }
        }

        $isoWeek = (new DateTimeImmutable('today'))->format('o') . '-W' . (new DateTimeImmutable('today'))->format('W');
        $found = false;
        foreach ($json['cohorts'] as $row) {
            if ($row['signup_week'] === $isoWeek) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Une cohorte pour la semaine courante doit exister si createdAt a été fixé.');
    }

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Progression p')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Challenge c')->execute();

        $admin = (new User())
            ->setUsername('admin')
            ->setEmail('admin@example.com')
            ->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'password'));
        $this->entityManager->persist($admin);

        $user = (new User())
            ->setUsername('user')
            ->setEmail('user@example.com')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $this->entityManager->persist($user);

        $challengeEcology = (new Challenge())
            ->setName('Energy Saver')
            ->setCategory(ChallengeCategory::ECOLOGY)
            ->setDescription('Reduce energy usage');
        $this->entityManager->persist($challengeEcology);

        $challengeCommunity = (new Challenge())
            ->setName('Waste Reducer')
            ->setCategory(ChallengeCategory::COMMUNITY)
            ->setDescription('Reduce waste');
        $this->entityManager->persist($challengeCommunity);


        $now = new DateTimeImmutable();

        for ($i = 0; $i < 5; $i++) {
            $startedAt = $now->sub(new DateInterval('P' . ($i * 3 + 5) . 'D'));
            $completedAt = $now->sub(new DateInterval('P' . ($i * 2) . 'D'));

            $p = (new Progression())
                ->setUser($user)
                ->setChallenge($challengeEcology)
                ->setStartedAt($startedAt)
                ->setStatus(ChallengeStatus::COMPLETED)
                ->setCompletedAt($completedAt);

            $this->entityManager->persist($p);
        }

        for ($i = 0; $i < 3; $i++) {
            $startedAt = $now->sub(new DateInterval('P' . ($i * 4 + 6) . 'D'));
            $completedAt = $now->sub(new DateInterval('P' . ($i * 3 + 1) . 'D'));

            $p = (new Progression())
                ->setUser($user)
                ->setChallenge($challengeCommunity)
                ->setStartedAt($startedAt)
                ->setStatus(ChallengeStatus::COMPLETED)
                ->setCompletedAt($completedAt);

            $this->entityManager->persist($p);
        }


        for ($i = 0; $i < 4; $i++) {
            $startedAt = $now->sub(new DateInterval('P' . ($i + 2) . 'D'));

            $p = (new Progression())
                ->setUser($user)
                ->setChallenge($challengeEcology)
                ->setStartedAt($startedAt)
                ->setStatus(ChallengeStatus::IN_PROGRESS);

            $this->entityManager->persist($p);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }


}

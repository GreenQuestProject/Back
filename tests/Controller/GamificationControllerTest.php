<?php

namespace App\Tests\Controller;

use App\Entity\Badge;
use App\Entity\BadgeUnlock;
use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Entity\XpLedger;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;


final class GamificationControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    public function testProfileReturnsXpLevelBadgesAndStreak(): void
    {
        $token = $this->jwtFor('admin@example.com');

        $this->client->request(
            'GET',
            '/api/gamification/profile',
            server: ['HTTP_Authorization' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $json = json_decode($this->client->getResponse()->getContent(), true, JSON_THROW_ON_ERROR);

        $this->assertSame(950, $json['xpTotal']);
        $this->assertSame(3, $json['level']);
        $this->assertSame(5, $json['currentStreakDays']);

        $this->assertIsArray($json['badges']);
        $codes = array_map(fn($b) => $b['code'] ?? null, $json['badges']);
        $this->assertContains('FIRST_STEPS', $codes);
        $this->assertContains('WEEK_STREAK', $codes);
    }

    private function jwtFor(string $email): string
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }

    public function testLeaderboardReturnsItems(): void
    {
        $token = $this->jwtFor('admin@example.com');

        $this->client->request('GET', '/api/gamification/leaderboard', [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_Authorization' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertIsArray($data['items']);

        $usernames = array_column($data['items'], 'username');
        $this->assertContains('alpha', $usernames, 'alpha doit être dans le leaderboard');

        $alpha = null;
        foreach ($data['items'] as $row) {
            if (($row['username'] ?? null) === 'alpha') {
                $alpha = $row;
                break;
            }
        }
        $this->assertNotNull($alpha, "L'entrée pour 'alpha' doit exister");
        $this->assertArrayHasKey('xp_total', $alpha);
        $this->assertTrue(is_numeric($alpha['xp_total']), 'xp_total doit être numérique (int ou numeric-string)');
        $this->assertGreaterThanOrEqual(0, (int)$alpha['xp_total'], 'xp_total doit être >= 0');

        $xp = array_map(fn($r) => (int)($r['xp_total'] ?? 0), $data['items']);
        $xpSorted = $xp;
        rsort($xpSorted);
        $this->assertSame($xpSorted, $xp, 'Le leaderboard doit être trié par xp_total décroissant');
    }

    public function testClaimBadPayloadReturns400(): void
    {
        $token = $this->jwtFor('admin@example.com');

        $this->client->request(
            'POST',
            '/api/gamification/claim',
            server: [
                'HTTP_Authorization' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);
        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('error', $json);
    }

    public function testClaimNotEligible403(): void
    {
        $token = $this->jwtFor('admin@example.com');

        $somePastWeek = (new DateTimeImmutable('2000-01-05'));
        $code = $this->weekCodeOf($somePastWeek);

        $this->client->request(
            'POST',
            '/api/gamification/claim',
            server: [
                'HTTP_Authorization' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['type' => 'quest', 'code' => $code], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(403);
        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('not_eligible', $json['status'] ?? null);
        $this->assertArrayHasKey('need', $json);
        $this->assertArrayHasKey('have', $json);
        $this->assertArrayHasKey('rule', $json);
    }

    private function weekCodeOf(DateTimeImmutable $date): string
    {
        return 'W' . $date->format('o') . '-' . $date->format('W');
    }

    public function testClaimEligibleThenAlreadyClaimed(): void
    {
        $token = $this->jwtFor('admin@example.com');

        $eligibleDate = (new DateTimeImmutable('today'))->modify('-2 days');
        $code = $this->weekCodeOf($eligibleDate);
        $reason = 'quest:' . $code;

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        $countBefore = (int)$this->em->createQuery(
            'SELECT COUNT(x.id) FROM App\Entity\XpLedger x WHERE x.user = :u AND x.reason = :r'
        )->setParameter('u', $user)->setParameter('r', $reason)->getSingleScalarResult();

        $this->client->request(
            'POST',
            '/api/gamification/claim',
            server: [
                'HTTP_Authorization' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['type' => 'quest', 'code' => $code], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();
        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('claimed', $json['status'] ?? null);
        $this->assertSame(100, (int)$json['xp_credited']);

        $countAfter = (int)$this->em->createQuery(
            'SELECT COUNT(x.id) FROM App\Entity\XpLedger x WHERE x.user = :u AND x.reason = :r'
        )->setParameter('u', $user)->setParameter('r', $reason)->getSingleScalarResult();
        $this->assertSame($countBefore + 1, $countAfter, 'Une entrée XpLedger doit être créée au premier claim');

        $this->client->request(
            'POST',
            '/api/gamification/claim',
            server: [
                'HTTP_Authorization' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['type' => 'quest', 'code' => $code], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();
        $json2 = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('already_claimed', $json2['status'] ?? null);

        $countAfter2 = (int)$this->em->createQuery(
            'SELECT COUNT(x.id) FROM App\Entity\XpLedger x WHERE x.user = :u AND x.reason = :r'
        )->setParameter('u', $user)->setParameter('r', $reason)->getSingleScalarResult();
        $this->assertSame($countAfter, $countAfter2, 'Aucune nouvelle entrée XpLedger ne doit être créée au second claim');
    }

    public function testProfileIncludesImpactAndCompletedCount(): void
    {
        $token = $this->jwtFor('admin@example.com');

        $this->client->request(
            'GET',
            '/api/gamification/profile',
            server: ['HTTP_Authorization' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $json = json_decode($this->client->getResponse()->getContent(), true, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('completedCount', $json);
        $this->assertArrayHasKey('impact', $json);
        $this->assertIsArray($json['impact']);
        $this->assertArrayHasKey('co2Kg', $json['impact']);
        $this->assertArrayHasKey('waterL', $json['impact']);
        $this->assertArrayHasKey('wasteKg', $json['impact']);

        $this->assertSame(5, (int)$json['completedCount']);

        $this->assertSame(0.0, (float)$json['impact']['co2Kg']);
        $this->assertSame(0.0, (float)$json['impact']['waterL']);
        $this->assertSame(0.0, (float)$json['impact']['wasteKg']);
    }

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\BadgeUnlock bu')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\XpLedger xl')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Progression p')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Challenge c')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Badge b')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();

        $admin = $this->makeUser('admin@example.com', 'admin', ['ROLE_USER']);
        $alpha = $this->makeUser('alpha@example.com', 'alpha', ['ROLE_USER']);
        $beta = $this->makeUser('beta@example.com', 'beta', ['ROLE_USER']);

        $challenge = (new Challenge())
            ->setName('Eco Challenge')
            ->setCategory(ChallengeCategory::ECOLOGY ?? ChallengeCategory::NONE)
            ->setDescription('Eco practice');
        $this->em->persist($challenge);

        $this->addXp($admin, 950, 'seed_admin');
        $this->addXp($alpha, 1600, 'seed_alpha');
        $this->addXp($beta, 1100, 'seed_beta');

        $badge1 = (new Badge())
            ->setCode('FIRST_STEPS')
            ->setName('First Steps')
            ->setDescription('Complete your first challenge')
            ->setRarity('common');
        $this->em->persist($badge1);

        $badge2 = (new Badge())
            ->setCode('WEEK_STREAK')
            ->setName('One Week Streak')
            ->setDescription('Complete challenges 7 days in a row')
            ->setRarity('rare');
        $this->em->persist($badge2);

        $unlock1 = (new BadgeUnlock())
            ->setBadge($badge1)
            ->setUser($admin)
            ->setUnlockedAt(new DateTimeImmutable('2025-08-01'));
        $this->em->persist($unlock1);

        $unlock2 = (new BadgeUnlock())
            ->setBadge($badge2)
            ->setUser($admin)
            ->setUnlockedAt(new DateTimeImmutable('2025-08-07'));
        $this->em->persist($unlock2);

        $today = (new DateTimeImmutable('today'))->setTime(12, 0);
        for ($i = 0; $i < 5; $i++) {
            $completed = $today->modify("-{$i} day");
            $started = $completed->modify('-6 hours');

            $p = (new Progression())
                ->setUser($admin)
                ->setChallenge($challenge)
                ->setStartedAt($started)
                ->setStatus(ChallengeStatus::COMPLETED)
                ->setCompletedAt($completed);

            $this->em->persist($p);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function makeUser(string $email, string $username, array $roles): User
    {
        $u = (new User())
            ->setEmail($email)
            ->setUsername($username)
            ->setRoles($roles);
        $u->setPassword($this->hasher->hashPassword($u, 'password'));
        $this->em->persist($u);
        return $u;
    }

    private function addXp(User $user, int $delta, string $reason): void
    {
        $xl = (new XpLedger())
            ->setUser($user)
            ->setDelta($delta)
            ->setReason($reason)
            ->setOccurredAt(new DateTimeImmutable());
        $this->em->persist($xl);
    }


}

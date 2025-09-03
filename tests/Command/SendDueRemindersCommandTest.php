<?php

namespace App\Tests\Command;

use App\Command\SendDueRemindersCommand;
use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\PushSubscription;
use App\Entity\Reminder;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use App\Service\PushSender;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SendDueRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var MockObject&PushSender */
    private $pushMock;
    private UserPasswordHasherInterface $passwordHasher;

    public function testSendDueReminders_sendsPayloads_andUpdatesRecurrence(): void
    {
        [$user, $challenge, $sub, $rNone, $rDaily, $rWeekly, $rFuture] = $this->makeSeed();

        $noneId = $rNone->getId();
        $dailyId = $rDaily->getId();
        $weeklyId = $rWeekly->getId();
        $futureId = $rFuture->getId();

        $dailyAt = $rDaily->getScheduledAtUtc();
        $weeklyAt = $rWeekly->getScheduledAtUtc();
        $futureAt = $rFuture->getScheduledAtUtc();

        $this->pushMock
            ->expects($this->exactly(3))
            ->method('sendWithReport')
            ->with(
                $this->callback(function ($subs) use ($sub) {
                    return is_array($subs)
                        && count($subs) === 1
                        && $subs[0] instanceof PushSubscription
                        && $subs[0]->getEndpoint() === $sub->getEndpoint();
                }),
                $this->callback(function ($payload) use ($challenge) {

                    foreach (['title', 'body', 'data', 'actions', 'tag', 'renotify', 'requireInteraction'] as $k) {
                        if (!array_key_exists($k, $payload)) return false;
                    }

                    if (!isset($payload['data']['url'])) return false;
                    if (!str_contains($payload['data']['url'], '/progression/')) return false;

                    $actions = array_column($payload['actions'], 'action');
                    foreach (['open', 'done', 'snooze'] as $needed) {
                        if (!in_array($needed, $actions, true)) return false;
                    }

                    return str_contains($payload['body'], (string)$challenge->getName());
                })
            )
            ->willReturnOnConsecutiveCalls(
                [['endpoint' => $sub->getEndpoint(), 'success' => true, 'status' => 201, 'reason' => null]],
                [['endpoint' => $sub->getEndpoint(), 'success' => true, 'status' => 201, 'reason' => null]],
                [['endpoint' => $sub->getEndpoint(), 'success' => false, 'status' => 410, 'reason' => 'Gone']],
            );

        $application = new Application(static::getContainer()->get('kernel'));
        $command = static::getContainer()->get(SendDueRemindersCommand::class);
        $application->add($command);
        $tester = new CommandTester($application->find('app:send-due-reminders'));
        $exitCode = $tester->execute([]);
        $this->assertSame(0, $exitCode);

        $this->em->clear();
        $repoReminder = $this->em->getRepository(Reminder::class);

        $noneAfter = $repoReminder->find($noneId);
        $dailyAfter = $repoReminder->find($dailyId);
        $weeklyAfter = $repoReminder->find($weeklyId);
        $futureAfter = $repoReminder->find($futureId);

        $this->assertFalse($noneAfter->isActive());

        $this->assertSameSecond(
            $dailyAt->add(new DateInterval('P1D')),
            $dailyAfter->getScheduledAtUtc(),
            'Reminder DAILY doit être replanifié +1 jour'
        );

        $this->assertSameSecond(
            $weeklyAt->add(new DateInterval('P1W')),
            $weeklyAfter->getScheduledAtUtc(),
            'Reminder WEEKLY doit être replanifié +1 semaine'
        );

        $this->assertTrue($futureAfter->isActive());
        $this->assertSameSecond($futureAt, $futureAfter->getScheduledAtUtc());
    }

    private function makeSeed(): array
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setUsername('user')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $this->em->persist($user);

        $challenge = (new Challenge())
            ->setName('Energy Saver')
            ->setCategory(ChallengeCategory::ECOLOGY ?? ChallengeCategory::NONE)
            ->setDescription('Reduce energy usage');
        $this->em->persist($challenge);

        $sub = (new PushSubscription())
            ->setUser($user)
            ->setActive(true)
            ->setEndpoint('https://push.example.test/' . bin2hex(random_bytes(6)))
            ->setP256dh(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='))
            ->setAuth(rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='))
            ->setEncoding('aes128gcm')
            ->setCreatedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->em->persist($sub);

        $mkProg = function () use ($user, $challenge) {
            $p = (new Progression())
                ->setUser($user)
                ->setChallenge($challenge)
                ->setStatus(ChallengeStatus::IN_PROGRESS)
                ->setStartedAt(new DateTimeImmutable('-1 day'));
            $this->em->persist($p);
            return $p;
        };

        $progNone = $mkProg();
        $progDaily = $mkProg();
        $progWeekly = $mkProg();
        $progFuture = $mkProg();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dueAt = $now->modify('-5 seconds');

        $rNone = (new Reminder())
            ->setProgression($progNone)
            ->setScheduledAtUtc($dueAt)
            ->setRecurrence('NONE')
            ->setTimezone('UTC')
            ->setActive(true);
        $this->em->persist($rNone);

        $rDaily = (new Reminder())
            ->setProgression($progDaily)
            ->setScheduledAtUtc($dueAt)
            ->setRecurrence('DAILY')
            ->setTimezone('UTC')
            ->setActive(true);
        $this->em->persist($rDaily);

        $rWeekly = (new Reminder())
            ->setProgression($progWeekly)
            ->setScheduledAtUtc($dueAt)
            ->setRecurrence('WEEKLY')
            ->setTimezone('UTC')
            ->setActive(true);
        $this->em->persist($rWeekly);

        $rFuture = (new Reminder())
            ->setProgression($progFuture)
            ->setScheduledAtUtc($dueAt->modify('+5 minutes'))
            ->setRecurrence('NONE')
            ->setTimezone('UTC')
            ->setActive(true);
        $this->em->persist($rFuture);

        $this->em->flush();

        return [$user, $challenge, $sub, $rNone, $rDaily, $rWeekly, $rFuture];
    }

    private function assertSameSecond(DateTimeInterface $expected, DateTimeInterface $actual, string $msg = ''): void
    {
        $this->assertSame($expected->format('Y-m-d H:i:s'), $actual->format('Y-m-d H:i:s'), $msg);
    }

    public function testDryRun_doesNotSend_andDoesNotReschedule(): void
    {
        [$user, $challenge, $sub, $rNone, $rDaily, $rWeekly, $rFuture] = $this->makeSeed();

        $noneId = $rNone->getId();
        $dailyId = $rDaily->getId();
        $weeklyId = $rWeekly->getId();
        $futureId = $rFuture->getId();

        $noneAt = $rNone->getScheduledAtUtc();
        $dailyAt = $rDaily->getScheduledAtUtc();
        $weeklyAt = $rWeekly->getScheduledAtUtc();
        $futureAt = $rFuture->getScheduledAtUtc();

        $this->pushMock
            ->expects($this->never())
            ->method('sendWithReport');

        $application = new Application(static::getContainer()->get('kernel'));
        $command = static::getContainer()->get(SendDueRemindersCommand::class);
        $application->add($command);
        $tester = new CommandTester($application->find('app:send-due-reminders'));
        $exitCode = $tester->execute(['--dry-run' => true]);
        $this->assertSame(0, $exitCode);

        $this->em->clear();
        $repoReminder = $this->em->getRepository(Reminder::class);

        $noneAfter = $repoReminder->find($noneId);
        $dailyAfter = $repoReminder->find($dailyId);
        $weeklyAfter = $repoReminder->find($weeklyId);
        $futureAfter = $repoReminder->find($futureId);

        $this->assertTrue($noneAfter->isActive());
        $this->assertSameSecond($noneAt, $noneAfter->getScheduledAtUtc());

        $this->assertTrue($dailyAfter->isActive());
        $this->assertSameSecond($dailyAt, $dailyAfter->getScheduledAtUtc());

        $this->assertTrue($weeklyAfter->isActive());
        $this->assertSameSecond($weeklyAt, $weeklyAfter->getScheduledAtUtc());

        $this->assertTrue($futureAfter->isActive());
        $this->assertSameSecond($futureAt, $futureAfter->getScheduledAtUtc());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\Reminder r')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\PushSubscription s')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Progression p')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Challenge c')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->pushMock = $this->getMockBuilder(PushSender::class)->disableOriginalConstructor()->getMock();
        static::getContainer()->set(PushSender::class, $this->pushMock);
    }
}

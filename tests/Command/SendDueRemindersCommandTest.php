<?php

namespace App\Tests\Command;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\PushSubscription;
use App\Entity\Reminder;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use App\Service\PushSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SendDueRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var MockObject&PushSender */
    private $pushMock;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Nettoyage minimal (adapte si besoin selon tes relations)
        $this->em->createQuery('DELETE FROM App\Entity\Reminder r')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\PushSubscription s')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Progression p')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Challenge c')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();

        // Mock PushSender et override dans le container de test
        $this->pushMock = $this->getMockBuilder(PushSender::class)->disableOriginalConstructor()->getMock();
        static::getContainer()->set(PushSender::class, $this->pushMock);
    }

    private function assertSameSecond(\DateTimeInterface $expected, \DateTimeInterface $actual, string $msg = ''): void
    {
        $this->assertSame($expected->format('Y-m-d H:i:s'), $actual->format('Y-m-d H:i:s'), $msg);
    }

    public function testSendDueReminders_sendsPayloads_andUpdatesRecurrence_withDistinctProgressions(): void
    {
        // === Seed de base ===
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

        // Souscription push active avec champs obligatoires
        $sub = (new PushSubscription())
            ->setUser($user)
            ->setActive(true)
            ->setEndpoint('https://push.example.test/'.bin2hex(random_bytes(6)))
            ->setP256dh(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='))
            ->setAuth(rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='))
            ->setEncoding('aes128gcm')
            ->setCreatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->persist($sub);

        // Une progression par rappel pour éviter uniq(progresssion_id, active)
        $mkProg = function (string $label) use ($user, $challenge) {
            $p = (new Progression())
                ->setUser($user)
                ->setChallenge($challenge)
                ->setStatus(ChallengeStatus::IN_PROGRESS)
                ->setStartedAt(new \DateTimeImmutable('-1 day'));
            $this->em->persist($p);
            return $p;
        };

        $progNone   = $mkProg('NONE');
        $progDaily  = $mkProg('DAILY');
        $progWeekly = $mkProg('WEEKLY');
        $progFuture = $mkProg('FUTURE');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dueAt = $now->modify('-5 seconds'); // au centre de la fenêtre de la command

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

// Non-dû : hors fenêtre
        $rFuture = (new Reminder())
            ->setProgression($progFuture)
            ->setScheduledAtUtc($dueAt->modify('+5 minutes'))
            ->setRecurrence('NONE')
            ->setTimezone('UTC')
            ->setActive(true);
        $this->em->persist($rFuture);

        $this->em->flush();

// Capture IDs + timestamps AVANT exécution (pas de clear ici)
        $noneId   = $rNone->getId();
        $dailyId  = $rDaily->getId();
        $weeklyId = $rWeekly->getId();
        $futureId = $rFuture->getId();

        $noneAt   = $rNone->getScheduledAtUtc();
        $dailyAt  = $rDaily->getScheduledAtUtc();
        $weeklyAt = $rWeekly->getScheduledAtUtc();
        $futureAt = $rFuture->getScheduledAtUtc();

// Exécution immédiate de la commande
        $application = new Application(static::getContainer()->get('kernel'));
        $command = static::getContainer()->get(\App\Command\SendDueRemindersCommand::class);
        $application->add($command);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:send-due-reminders'));
        $exitCode = $tester->execute([]);
        $this->assertSame(0, $exitCode);

// Recharge propre pour assertions
        $this->em->clear();
        $repoReminder = $this->em->getRepository(\App\Entity\Reminder::class);

        $noneAfter   = $repoReminder->find($noneId);
        $dailyAfter  = $repoReminder->find($dailyId);
        $weeklyAfter = $repoReminder->find($weeklyId);
        $futureAfter = $repoReminder->find($futureId);

// NONE -> désactivé
        $this->assertFalse($noneAfter->isActive());
// DAILY -> +1 jour
        $this->assertSameSecond(
            $dailyAt->add(new \DateInterval('P1D')),
            $dailyAfter->getScheduledAtUtc(),
            'Reminder DAILY doit être replanifié +1 jour'
        );

// WEEKLY -> +1 semaine
        $this->assertSameSecond(
            $weeklyAt->add(new \DateInterval('P1W')),
            $weeklyAfter->getScheduledAtUtc(),
            'Reminder WEEKLY doit être replanifié +1 semaine'
        );

// Non-dû -> inchangé et actif
        $this->assertTrue($futureAfter->isActive());
        $this->assertSameSecond($futureAt, $futureAfter->getScheduledAtUtc());

    }
}

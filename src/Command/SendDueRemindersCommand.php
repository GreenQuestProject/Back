<?php

namespace App\Command;

use App\Entity\PushSubscription;
use App\Entity\Reminder;
use App\Service\PushSender;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface as EM;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand(name: 'app:send-due-reminders', description: 'Envoie les rappels échus (push web)')]
class SendDueRemindersCommand extends Command
{
    public function __construct(
        private readonly EM         $em,
        private readonly PushSender $push
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Fenêtre d\'éligibilité en secondes (<= now)', '90')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre max de rappels à traiter', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\'envoie rien (aperçu)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filtrer par ID utilisateur');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $store = new FlockStore(sys_get_temp_dir());
        $factory = new LockFactory($store);
        $lock = $factory->createLock('app:send-due-reminders', 300);
        if (!$lock->acquire()) {
            $io->warning('Une autre exécution est en cours. Sortie.');
            return Command::SUCCESS;
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = max(0, (int)$input->getOption('window'));
        $limit = max(1, (int)$input->getOption('limit'));
        $dryRun = (bool)$input->getOption('dry-run');
        $userId = $input->getOption('user');

        $io->section(sprintf(
            'Départ %s (UTC), fenêtre %ds, limite %d%s',
            $nowUtc->format('Y-m-d H:i:s'),
            $window,
            $limit,
            $dryRun ? ' [DRY-RUN]' : ''
        ));

        $q = $this->em->createQueryBuilder()
            ->select('r', 'p', 'u', 'c')
            ->from(Reminder::class, 'r')
            ->join('r.progression', 'p')
            ->join('p.user', 'u')
            ->join('p.challenge', 'c')
            ->where('r.active = 1')
            ->andWhere('r.scheduledAtUtc <= :ceil')
            ->setParameter('ceil', $nowUtc)
            ->orderBy('r.scheduledAtUtc', 'ASC')
            ->setMaxResults($limit);

        if ($window > 0) {
            $floor = $nowUtc->sub(new DateInterval('PT' . $window . 'S'));
            $q->andWhere('r.scheduledAtUtc >= :floor')->setParameter('floor', $floor);
        }

        if ($userId) {
            $q->andWhere('u.id = :uid')->setParameter('uid', (int)$userId);
        }

        $query = $q->getQuery();
        $iter = $query->toIterable();

        $count = 0;
        $sent = 0;
        $failed = 0;

        foreach ($iter as $reminder/** @var Reminder $reminder */) {
            $count++;

            $user = $reminder->getProgression()->getUser();
            $challenge = $reminder->getProgression()->getChallenge();

            $subs = $this->em->getRepository(PushSubscription::class)
                ->findBy(['user' => $user, 'active' => true]);

            if (!$subs) {
                $io->writeln(sprintf('<comment>[%d] Pas d\'abonnement actif pour user #%d</comment>', $reminder->getId(), $user->getId()));
                $this->reschedule($reminder);
                continue;
            }
            $frontendBaseUrl = rtrim($_ENV['FRONTEND_BASE_URL'] ?? '', '/');

            $payload = [
                'title' => 'Rappel défi',
                'body' => sprintf('Il est temps de faire : %s', $challenge->getName()),
                'data' => ['url' => $frontendBaseUrl . '/progression/', 'reminderId' => $reminder->getId()],
                'actions' => [
                    ['action' => 'open', 'title' => 'Ouvrir'],
                    ['action' => 'done', 'title' => 'Fait'],
                    ['action' => 'snooze', 'title' => 'Plus tard'],
                ],
                'tag' => 'reminder-' . $reminder->getId() . '-' . time(),
                'renotify' => true,
                'requireInteraction' => true,
            ];

            if ($dryRun) {
                $io->writeln(sprintf('<info>[DRY] #%d</info> → user #%d (%d subs) • %s',
                    $reminder->getId(), $user->getId(), count($subs), $challenge->getName()
                ));
                continue;
            }

            $reports = $this->push->sendWithReport($subs, $payload);

            $okForAny = false;
            foreach ($reports as $rep) {
                $ok = !empty($rep['success']);
                $status = $rep['status'] ?? null;
                $reason = $rep['reason'] ?? ($rep['exception'] ?? null);

                $io->writeln(sprintf('%s  [%s]  %s  %s',
                    $ok ? '<info>OK</info>' : '<error>FAIL</error>',
                    $status ?? '-',
                    substr($rep['endpoint'] ?? '', 0, 64),
                    $reason ?? '-'
                ));

                if ($ok) {
                    $okForAny = true;
                } else {
                    $failed++;
                }
            }

            if ($okForAny) {
                $sent++;
            }
            $this->reschedule($reminder);

            if (($count % 100) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        $this->em->flush();

        $io->success(sprintf('Rappels traités: %d • succès: %d • échecs: %d%s',
            $count, $sent, $failed, $dryRun ? ' • (dry-run)' : ''
        ));

        $lock->release();
        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function reschedule(Reminder $reminder): void
    {
        $rec = $reminder->getRecurrence();
        if ($rec === 'DAILY' || $rec === 'WEEKLY') {
            $interval = $rec === 'DAILY' ? new DateInterval('P1D') : new DateInterval('P1W');

            $tzId = method_exists($reminder, 'getTimezone') ? $reminder->getTimezone() : null;
            if ($tzId) {
                $tz = new DateTimeZone($tzId);
                $nextLocal = (new DateTimeImmutable('now', $tz))
                    ->setTimestamp($reminder->getScheduledAtUtc()->getTimestamp())
                    ->add($interval);
                $reminder->setScheduledAtUtc($nextLocal->setTimezone(new DateTimeZone('UTC')));
            } else {
                $reminder->setScheduledAtUtc(
                    $reminder->getScheduledAtUtc()->add($interval)
                );
            }
        } else {
            $reminder->setActive(false);
        }
    }
}

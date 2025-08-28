<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\EntityManagerInterface as EM;
use App\Entity\Reminder;
use App\Entity\PushSubscription;
use App\Service\PushSender;

#[AsCommand(name: 'app:send-due-reminders')]
class SendDueRemindersCommand extends Command
{
    public function __construct(private EM $em, private PushSender $push)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

// marge de +/- 30s
        $from = $now->modify('-30 seconds');
        $to = $now->modify('+30 seconds');

        $due = $this->em->createQueryBuilder()
            ->select('r, p, u')
            ->from(Reminder::class, 'r')
            ->join('r.progression', 'p')
            ->join('p.user', 'u')
            ->where('r.active = 1 AND r.scheduledAtUtc BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getResult();

        foreach ($due as $rem) {
            $user = $rem->getProgression()->getUser();
            $subs = $this->em->getRepository(PushSubscription::class)->findBy(['user'=>$user, 'active'=>true]);

            $challenge = $rem->getProgression()->getChallenge();
            $payload = [
                'title' => 'Rappel défi',
                'body'  => sprintf('Il est temps de faire : %s', $challenge->getName()),
                'data'  => ['url' => '/defis/' . $challenge->getId(), 'reminderId' => $rem->getId()],
                'actions' => [
                    ['action'=>'open','title'=>'Ouvrir'],
                    ['action'=>'done','title'=>'Fait'],
                    ['action'=>'snooze','title'=>'Plus tard'],
                ],
            ];
            $this->push->send($subs, $payload);

            // récurrence
            if ($rem->getRecurrence()==='DAILY')      $rem->setScheduledAtUtc($rem->getScheduledAtUtc()->add(new \DateInterval('P1D')));
            elseif ($rem->getRecurrence()==='WEEKLY') $rem->setScheduledAtUtc($rem->getScheduledAtUtc()->add(new \DateInterval('P1W')));
            else                                      $rem->setActive(false);
        }
        $this->em->flush();

        return Command::SUCCESS;
    }
}
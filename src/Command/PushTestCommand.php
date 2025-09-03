<?php

namespace App\Command;

use App\Repository\PushSubscriptionRepository;
use App\Service\PushSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:push-test', description: 'Envoie une notif test à tes abonnements')]
class PushTestCommand extends Command
{
    public function __construct(
        private readonly PushSender                 $pushSender,
        private readonly PushSubscriptionRepository $repo,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subs = $this->repo->findBy(['active' => true]);
        if (!$subs) {
            $output->writeln('<comment>Aucune subscription active</comment>');
            return Command::SUCCESS;
        }

        $payload = [
            'title' => 'Test push',
            'body' => 'Coucou 👋 ça marche !',
            'data' => ['url' => '/defis']
        ];
        $reports = $this->pushSender->sendWithReport($subs, $payload);

        foreach ($reports as $r) {
            $ok = $r['success'] ? '<info>OK</info>' : '<error>FAIL</error>';
            $status = $r['status'] ?? '-';
            $reason = $r['reason'] ?? ($r['exception'] ?? '-');
            $output->writeln("$ok  [$status]  {$r['endpoint']}  $reason");
        }

        return Command::SUCCESS;
    }
}

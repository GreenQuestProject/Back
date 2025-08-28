<?php

namespace App\Service;

use ErrorException;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use App\Entity\PushSubscription;

class PushSender
{
    private WebPush $webPush;

    /**
     * @throws ErrorException
     */
    public function __construct(string $public, string $private, string $subject)
    {
        $this->webPush = new WebPush([
            'VAPID' => ['subject' => $subject, 'publicKey' => $public, 'privateKey' => $private]
        ]);
    }

    /**
     * @param PushSubscription[] $subs
     * @throws ErrorException
     */
    public function send(array $subs, array $payload): void
    {
        foreach ($subs as $s) {
            $sub = Subscription::create([
                'endpoint' => $s->getEndpoint(),
                'publicKey' => $s->getP256dh(),
                'authToken' => $s->getAuth(),
                'contentEncoding' => $s->getEncoding(),
            ]);
            $this->webPush->queueNotification($sub, json_encode($payload));
        }
/*
        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                // Option : désactiver les endpoints invalides
                $endpoint = (string) $report->getRequest()->getUri();
            }
        }
*/
    }
}
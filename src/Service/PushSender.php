<?php
namespace App\Service;

use ErrorException;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use App\Entity\PushSubscription;
use Doctrine\ORM\EntityManagerInterface as EM;
use Psr\Log\LoggerInterface;

class PushSender
{
    private WebPush $webPush;

    /**
     * @throws ErrorException
     */
    public function __construct(
        string $public,
        string $private,
        string $subject,
        private EM $em,
        private LoggerInterface $logger,
        ?WebPush $webPush = null
    ) {
        $this->webPush = $webPush ?? new WebPush([
            'VAPID' => [
                'subject'    => $subject,
                'publicKey'  => $public,
                'privateKey' => $private,
            ],
        ]);
    }

    /** @param PushSubscription[] $subs
     * @throws ErrorException
     */
    public function send(array $subs, array $payload): void
    {
        foreach ($subs as $s) {
            $sub = Subscription::create([
                'endpoint'        => $s->getEndpoint(),
                'publicKey'       => $s->getP256dh(),
                'authToken'       => $s->getAuth(),
                'contentEncoding' => $s->getEncoding(),
            ]);
            $this->webPush->queueNotification($sub, json_encode($payload));
        }

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $endpoint = (string) $report->getRequest()->getUri();
            }
        }
    }

    /** @param PushSubscription[] $subs */
    public function sendWithReport(array $subs, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $out = [];

        foreach ($subs as $s) {
            try {
                $subscription = Subscription::create([
                    'endpoint'        => $s->getEndpoint(),
                    'keys'            => ['p256dh' => $s->getP256dh(), 'auth' => $s->getAuth()],
                    'contentEncoding' => $s->getEncoding() ?: 'aes128gcm',
                ]);

                $report = $this->webPush->sendOneNotification(
                    $subscription,
                    $json,
                    ['TTL' => 60, 'urgency' => 'high']
                );

                $row = [
                    'endpoint' => $s->getEndpoint(),
                    'success'  => $report->isSuccess(),
                    'status'   => $report->getResponse()?->getStatusCode(),
                    'reason'   => $report->getReason(),
                ];
                $out[] = $row;

                if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                    $s->setActive(false);
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                $this->logger->error('Push exception', ['msg' => $e->getMessage()]);
                $out[] = ['endpoint' => $s->getEndpoint(), 'success' => false, 'exception' => $e->getMessage()];
            }
        }

        return $out;
    }
}

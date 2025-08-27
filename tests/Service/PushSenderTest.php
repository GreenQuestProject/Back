<?php

namespace App\Tests\Service;

use App\Entity\PushSubscription;
use App\Service\PushSender;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class PushSenderTest extends TestCase
{
    private function makeSub(
        string $endpoint = 'https://push.example/endpoint-1',
        string $p256dh = 'p256',
        string $auth = 'auth',
        string $encoding = 'aes128gcm'
    ): PushSubscription {
        $s = new PushSubscription();
        $s->setEndpoint($endpoint);
        $s->setP256dh($p256dh);
        $s->setAuth($auth);
        $s->setEncoding($encoding);
        $s->setActive(true);
        return $s;
    }


    /**
     * @throws \ReflectionException
     */
    private function buildSenderWithMock(WebPush $mock): PushSender
    {
        $rc = new ReflectionClass(PushSender::class);
        /** @var PushSender $sender */
        $sender = $rc->newInstanceWithoutConstructor();

        $prop = new ReflectionProperty(PushSender::class, 'webPush');
        $prop->setAccessible(true);
        $prop->setValue($sender, $mock);

        return $sender;
    }

    /**
     * @throws Exception
     * @throws \ErrorException
     */
    public function testSendQueuesOneNotificationWithCorrectPayload(): void
    {
        $sub = $this->makeSub('https://push.example/ep-1', 'pkey', 'atok', 'aes128gcm');
        $payload = ['title' => 'Hello', 'body' => 'World'];

        $webPushMock = $this->createMock(WebPush::class);
        $webPushMock->expects($this->once())
            ->method('queueNotification')
            ->with(
                $this->callback(function ($arg) {
                    $this->assertInstanceOf(WebPushSubscription::class, $arg);
                    $this->assertSame('https://push.example/ep-1', $arg->getEndpoint());
                    $this->assertSame('pkey', $arg->getPublicKey());
                    $this->assertSame('atok', $arg->getAuthToken());
                    $this->assertSame('aes128gcm', $arg->getContentEncoding());
                    return true;
                }),
                $this->equalTo(json_encode(['title' => 'Hello', 'body' => 'World']))
            );

        $sender = $this->buildSenderWithMock($webPushMock);
        $sender->send([$sub], $payload);
    }

    /**
     * @throws Exception
     * @throws \ErrorException
     */
    public function testSendQueuesMultipleNotifications(): void
    {
        $subs = [
            $this->makeSub('https://push.example/ep-1'),
            $this->makeSub('https://push.example/ep-2'),
        ];
        $payload = ['n' => 2];

        $webPushMock = $this->createMock(WebPush::class);
        $seen = [];
        $webPushMock->expects($this->exactly(2))
            ->method('queueNotification')
            ->with(
                $this->callback(function ($arg) use (&$seen) {
                    $this->assertInstanceOf(WebPushSubscription::class, $arg);
                    $endpoint = $arg->getEndpoint();
                    $this->assertContains($endpoint, ['https://push.example/ep-1', 'https://push.example/ep-2']);
                    $seen[] = $endpoint;
                    return true;
                }),
                $this->equalTo(json_encode(['n' => 2]))
            );

        $sender = $this->buildSenderWithMock($webPushMock);
        $sender->send($subs, $payload);

        $this->assertEqualsCanonicalizing(['https://push.example/ep-1', 'https://push.example/ep-2'], $seen);
    }

    /**
     * @throws Exception
     * @throws \ErrorException
     */
    public function testSendWithEmptyListDoesNothing(): void
    {
        $webPushMock = $this->createMock(WebPush::class);
        $webPushMock->expects($this->never())->method('queueNotification');

        $sender = $this->buildSenderWithMock($webPushMock);
        $sender->send([], ['anything' => 'goes']);

        $this->assertTrue(true);
    }
}

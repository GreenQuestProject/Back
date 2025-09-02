<?php

namespace App\Tests\Service;

use App\Entity\PushSubscription;
use App\Service\PushSender;
use Doctrine\ORM\EntityManagerInterface;
use ErrorException;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class PushSenderTest extends TestCase
{
    private function b64url(int $lenBytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($lenBytes)), '+/', '-_'), '=');
    }

    private function makeSub(
        string  $endpoint = 'https://push.example/endpoint-1',
        ?string $p256dh = null,
        ?string $auth = null
    ): PushSubscription
    {
        $s = new PushSubscription();
        $s->setEndpoint($endpoint);
        $s->setP256dh($p256dh ?? $this->b64url(32)); // clé publique ~43 chars
        $s->setAuth($auth ?? $this->b64url(16));     // auth ~22 chars
        $s->setEncoding('aes128gcm');
        $s->setActive(true);
        return $s;
    }

    private function emptyGenerator(): \Generator
    {
        yield from [];
    }

    /**
     * @throws Exception
     * @throws ErrorException
     */
    private function makeSender(
        WebPush                 $webPush,
        ?EntityManagerInterface $em = null,
        ?LoggerInterface        $logger = null
    ): PushSender
    {
        $em ??= $this->createStub(EntityManagerInterface::class);
        $logger ??= $this->createStub(LoggerInterface::class);

        return new \App\Service\PushSender(
            'PUBLIC', 'PRIVATE', 'mailto:test@example.com',
            $em, $logger, $webPush
        );
    }

    /**
     * @throws Exception
     * @throws ErrorException
     * @throws \Exception
     */
    public function testSendQueuesOneNotificationWithCorrectPayload(): void
    {
        $sub = $this->makeSub('https://push.example/ep-1', 'pkey', 'atok');
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

        $webPushMock->expects($this->once())
            ->method('flush')
            ->willReturn($this->emptyGenerator());

        $sender = $this->makeSender($webPushMock);
        $sender->send([$sub], $payload);
        $this->assertTrue(true);
    }

    /**
     * @throws Exception
     * @throws ErrorException
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

        $webPushMock->expects($this->once())
            ->method('flush')
            ->willReturn($this->emptyGenerator());

        $sender = $this->makeSender($webPushMock);
        $sender->send($subs, $payload);

        $this->assertEqualsCanonicalizing(['https://push.example/ep-1', 'https://push.example/ep-2'], $seen);
    }

    /**
     * @throws Exception
     * @throws ErrorException
     */
    public function testSendWithEmptyListDoesNothingButFlushIsCalled(): void
    {
        $webPushMock = $this->createMock(WebPush::class);
        $webPushMock->expects($this->never())->method('queueNotification');
        $webPushMock->expects($this->once())->method('flush')->willReturn($this->emptyGenerator());

        $sender = $this->makeSender($webPushMock);
        $sender->send([], ['anything' => 'goes']);

        $this->assertTrue(true);
    }

    /**
     * @throws Exception
     * @throws ErrorException
     */
    public function testSendWithReport_successAndGone410_deactivatesAndFlushesEm(): void
    {
        $subOk = $this->makeSub('https://push.example/ok');
        $subGone = $this->makeSub('https://push.example/gone');

        $emMock = $this->createMock(EntityManagerInterface::class);
        $emMock->expects($this->once())->method('flush');

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->method('error');

        $resp201 = $this->createMock(ResponseInterface::class);
        $resp201->method('getStatusCode')->willReturn(201);

        $resp410 = $this->createMock(ResponseInterface::class);
        $resp410->method('getStatusCode')->willReturn(410);

        $reportOk = $this->createMock(MessageSentReport::class);
        $reportOk->method('isSuccess')->willReturn(true);
        $reportOk->method('getResponse')->willReturn($resp201);
        $reportOk->method('getReason')->willReturn('');
        $reportOk->method('isSubscriptionExpired')->willReturn(false);

        $reportGone = $this->createMock(MessageSentReport::class);
        $reportGone->method('isSuccess')->willReturn(false);
        $reportGone->method('getResponse')->willReturn($resp410);
        $reportGone->method('getReason')->willReturn('Gone');
        $reportGone->method('isSubscriptionExpired')->willReturn(true);

        $webPushMock = $this->createMock(WebPush::class);
        $webPushMock->expects($this->exactly(2))
            ->method('sendOneNotification')
            ->with($this->anything(), $this->isString(), $this->anything())
            ->willReturnOnConsecutiveCalls($reportOk, $reportGone);

        $sender = $this->makeSender($webPushMock, $emMock, $loggerMock);
        $rows = $sender->sendWithReport([$subOk, $subGone], ['title' => 'T', 'body' => 'B']);

        $byEndpoint = [];
        foreach ($rows as $r) {
            $byEndpoint[$r['endpoint']] = $r;
        }

        $this->assertArrayHasKey($subGone->getEndpoint(), $byEndpoint);
        $rowGone = $byEndpoint[$subGone->getEndpoint()];
        $this->assertFalse($rowGone['success']);
        $this->assertFalse($subGone->isActive());
    }

    public function testSendWithReport_catchesException_andLogsError(): void
    {
        $sub = $this->makeSub('https://push.example/boom');

        $emMock = $this->createMock(EntityManagerInterface::class);
        $emMock->expects($this->never())->method('flush');

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('error')
            ->with($this->equalTo('Push exception'), $this->arrayHasKey('msg'));

        $webPushMock = $this->createMock(WebPush::class);
        $webPushMock->expects($this->once())
            ->method('sendOneNotification')
            ->willThrowException(new \RuntimeException('x-plode'));

        $sender = new \App\Service\PushSender(
            'PUBLIC', 'PRIVATE', 'mailto:test@example.com',
            $emMock, $loggerMock, $webPushMock
        );

        $rows = $sender->sendWithReport([$sub], ['title' => 'T']);

        $this->assertCount(1, $rows);
        $this->assertSame('https://push.example/boom', $rows[0]['endpoint']);
        $this->assertFalse($rows[0]['success']);
        $this->assertArrayHasKey('exception', $rows[0]);
        $this->assertStringContainsString('x-plode', $rows[0]['exception']);
    }

}

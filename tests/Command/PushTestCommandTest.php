<?php
namespace App\Tests\Command;

use App\Command\PushTestCommand;
use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use App\Service\PushSender;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PushTestCommandTest extends KernelTestCase
{
    /** @var MockObject&PushSender */
    private $pushMock;

    /** @var MockObject&PushSubscriptionRepository */
    private $repoMock;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->pushMock = $this->getMockBuilder(PushSender::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->repoMock = $this->getMockBuilder(PushSubscriptionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
    }

    public function test_noActiveSubscriptions_printsMessage_andReturnsSuccess(): void
    {
        $this->repoMock->expects($this->once())
            ->method('findBy')
            ->with(['active' => true])
            ->willReturn([]); // aucune sub

        $this->pushMock->expects($this->never())->method('sendWithReport');

        $application = new Application(static::getContainer()->get('kernel'));
        $command = new PushTestCommand($this->pushMock, $this->repoMock);
        $application->add($command);

        $tester = new CommandTester($application->find('app:push-test'));
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Aucune subscription active', $tester->getDisplay());
    }

    public function test_withActiveSubscriptions_callsSender_andPrintsReports(): void
    {
        $s1 = (new PushSubscription())
            ->setActive(true)
            ->setEndpoint('https://push.example.test/aaa')
            ->setP256dh('p256dh-1')
            ->setAuth('auth-1')
            ->setEncoding('aes128gcm');

        $s2 = (new PushSubscription())
            ->setActive(true)
            ->setEndpoint('https://push.example.test/bbb')
            ->setP256dh('p256dh-2')
            ->setAuth('auth-2')
            ->setEncoding('aes128gcm');

        $this->repoMock->expects($this->once())
            ->method('findBy')
            ->with(['active' => true])
            ->willReturn([$s1, $s2]);

        $this->pushMock->expects($this->once())
            ->method('sendWithReport')
            ->with(
                $this->callback(function ($subs) use ($s1, $s2) {
                    return \is_array($subs) && \count($subs) === 2
                        && $subs[0] === $s1 && $subs[1] === $s2;
                }),
                $this->callback(function ($payload) {
                    return \is_array($payload)
                        && ($payload['title'] ?? null) === 'Test push'
                        && ($payload['body'] ?? null) === 'Coucou 👋 ça marche !'
                        && isset($payload['data']['url']) && $payload['data']['url'] === '/defis';
                })
            )
            ->willReturn([
                ['endpoint' => $s1->getEndpoint(), 'success' => true,  'status' => 201, 'reason' => null],
                ['endpoint' => $s2->getEndpoint(), 'success' => false, 'status' => 410, 'reason' => 'Gone'],
            ]);

        $application = new Application(static::getContainer()->get('kernel'));
        $command = new PushTestCommand($this->pushMock, $this->repoMock);
        $application->add($command);

        $tester = new CommandTester($application->find('app:push-test'));
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $out = $tester->getDisplay();

        $plain = preg_replace('/<[^>]+>/', '', $out);

        $this->assertStringContainsString('OK  [201]  https://push.example.test/aaa', $plain);
        $this->assertStringContainsString('FAIL  [410]  https://push.example.test/bbb  Gone', $plain);
    }
}

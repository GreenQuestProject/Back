<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\PushSubscription;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PushControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private User $user;
    private User $otherUser;
    private string $jwt = '';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $c = self::getContainer();

        $this->em     = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\PushSubscription s')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();

        // Users
        $this->user = (new User())
            ->setUsername('username')
            ->setEmail('username@example.test')
            ->setRoles(['ROLE_USER']);
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->em->persist($this->user);

        $this->otherUser = (new User())
            ->setUsername('other')
            ->setEmail('other@example.test')
            ->setRoles(['ROLE_USER']);
        $this->otherUser->setPassword($hasher->hashPassword($this->otherUser, 'password'));
        $this->em->persist($this->otherUser);

        $this->em->flush();

        // Auth JWT
        $this->jwt = $this->getJwtToken('username', 'password');
        self::assertNotSame('', $this->jwt);
    }

    private function getJwtToken(string $username, string $password): string
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $response = json_decode($this->client->getResponse()->getContent(), true);

        return $response['token'] ?? '';
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt,
        ], $extra);
    }

    private function createSub(User $user, string $endpoint, bool $active = true): PushSubscription
    {
        $s = (new PushSubscription())
            ->setUser($user)
            ->setEndpoint($endpoint) // <-- calcule endpointHash dans le setter
            ->setP256dh('p256')
            ->setAuth('auth')
            ->setEncoding('aes128gcm')
            ->setActive($active)
            ->setCreatedAt(new DateTimeImmutable());
        $this->em->persist($s);
        $this->em->flush();

        return $s;
    }

    public function testSubscribeCreatesNewSubscription(): void
    {
        $endpoint = 'https://push.example/ep-1';
        $payload = [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
        ];

        $this->client->request(
            'POST',
            '/api/push/subscribe',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var PushSubscription|null $sub */
        $sub = $this->em->getRepository(PushSubscription::class)
            ->findOneBy(['endpointHash' => hash('sha256', $endpoint)]);
        self::assertNotNull($sub);
        self::assertSame($this->user->getId(), $sub->getUser()->getId());
        self::assertSame('k1', $sub->getP256dh());
        self::assertSame('a1', $sub->getAuth());
        self::assertSame('aes128gcm', $sub->getEncoding());
        self::assertTrue($sub->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getCreatedAt());
    }

    public function testSubscribeUpdatesExistingByEndpointHash(): void
    {
        // existant pour le même endpoint
        $endpoint = 'https://push.example/dupe';
        $existing = $this->createSub($this->user, $endpoint, true);

        $this->client->request(
            'POST',
            '/api/push/subscribe',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode([
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => 'newP', 'auth' => 'newA'],
            ], JSON_THROW_ON_ERROR)
        );

        // ⬇️ mise à jour => 200 OK (et plus 201)
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var PushSubscription $sub */
        $sub = $this->em->getRepository(PushSubscription::class)
            ->findOneBy(['endpointHash' => hash('sha256', $endpoint)]);
        self::assertNotNull($sub);
        // on a mis à jour le même enregistrement
        self::assertSame($existing->getId(), $sub->getId());
        self::assertSame('newP', $sub->getP256dh());
        self::assertSame('newA', $sub->getAuth());
        self::assertTrue($sub->isActive());
    }

    public function testSubscribeWithMissingKeysDefaults(): void
    {
        $endpoint = 'https://push.example/no-keys';
        $this->client->request(
            'POST',
            '/api/push/subscribe',
            server: $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['endpoint' => $endpoint], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var PushSubscription $sub */
        $sub = $this->em->getRepository(PushSubscription::class)
            ->findOneBy(['endpointHash' => hash('sha256', $endpoint)]);
        self::assertSame('', $sub->getP256dh());
        self::assertSame('', $sub->getAuth());
        self::assertSame('aes128gcm', $sub->getEncoding());
    }

    public function testUnsubscribeDeactivatesOnlyCurrentUserActiveSubscriptions(): void
    {
        // user courant
        $this->createSub($this->user, 'https://push.example/ep-a', true);
        $this->createSub($this->user, 'https://push.example/ep-b', true);
        $this->createSub($this->user, 'https://push.example/ep-old', false);

        // autre user
        $this->createSub($this->otherUser, 'https://push.example/ep-foreign', true);

        $this->client->request('POST', '/api/push/unsubscribe', server: $this->authHeaders());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $repo = $this->em->getRepository(PushSubscription::class);
        $mine  = $repo->findBy(['user' => $this->user]);
        foreach ($mine as $s) {
            self::assertFalse($s->isActive(), 'Tous les subs du user doivent être inactifs');
        }

        /** @var PushSubscription $foreign */
        $foreign = $repo->findOneBy(['endpointHash' => hash('sha256', 'https://push.example/ep-foreign')]);
        self::assertTrue($foreign->isActive());
    }

    public function testUnsubscribeWhenNoActiveSubscriptions(): void
    {
        // Aucun sub actif pour l'utilisateur courant
        $this->createSub($this->user, 'https://push.example/ep-inactive', false);

        $this->client->request('POST', '/api/push/unsubscribe', server: $this->authHeaders());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }
}

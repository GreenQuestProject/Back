<?php

namespace App\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\PushSubscription;

final class PushController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param Request $req
     * @return JsonResponse
     */
    #[Route('api/push/subscribe', name: 'push_subscribe', methods: ['POST'])]
    public function subscribe(Request $req): JsonResponse {
        $payload = json_decode($req->getContent(), true);

        $endpoint = $payload['endpoint'];
        $keys = $payload['keys'] ?? [];

        $sub = $this->em->getRepository(PushSubscription::class)
            ->findOneBy(['endpoint' => $endpoint]) ?? new PushSubscription();
        $sub->setUser($this->getUser());
        $sub->setEndpoint($endpoint);
        $sub->setP256dh($keys['p256dh'] ?? '');
        $sub->setAuth($keys['auth'] ?? '');
        $sub->setEncoding('aes128gcm');
        $sub->setActive(true);
        $sub->setCreatedAt(new DateTimeImmutable());

        $this->em->persist($sub);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_CREATED);
    }

    /**
     * @return JsonResponse
     */
    #[Route('api/push/unsubscribe', name: 'push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(): JsonResponse {
        $user = $this->getUser();
        $subs = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user, 'active' => true]);
        foreach ($subs as $s) { $s->setActive(false); }
        $this->em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

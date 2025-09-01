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
use App\Service\PushSender;
final class PushController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly PushSender $push) {}

    /**
     * @param Request $req
     * @return JsonResponse
     */
    #[Route('api/push/subscribe', name: 'push_subscribe', methods: ['POST'])]
    public function subscribe(Request $req): JsonResponse {
        $payload = json_decode($req->getContent(), true) ?? [];
        $endpoint = $payload['endpoint'] ?? null;
        $keys = $payload['keys'] ?? [];

        if (!$endpoint) {
            return new JsonResponse(['error' => 'Missing endpoint'], Response::HTTP_BAD_REQUEST);
        }

        $hash = hash('sha256', $endpoint);

        $repo = $this->em->getRepository(PushSubscription::class);
        $sub  = $repo->findOneBy(['endpointHash' => $hash]);

        $isNew = false;
        if (!$sub) {
            $sub = new PushSubscription();
            $isNew = true;
            $sub->setCreatedAt(new DateTimeImmutable());
        }

        $sub->setUser($this->getUser());
        $sub->setEndpoint($endpoint);                 // <-- met aussi à jour endpointHash
        $sub->setP256dh($keys['p256dh'] ?? '');
        $sub->setAuth($keys['auth'] ?? '');
        $sub->setEncoding('aes128gcm');               // ok, mais optionnel (voir note)
        $sub->setActive(true);

        $this->em->persist($sub);
        $this->em->flush();

        return new JsonResponse(null, $isNew ? Response::HTTP_CREATED : Response::HTTP_OK);
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

    #[Route('/api/push/debug-now', methods:['POST'])]
    public function debugNow(): JsonResponse {
        $u = $this->getUser();
        $subs = $this->em->getRepository(PushSubscription::class)->findBy(['user'=>$u, 'active'=>true]);
        if (!$subs) return $this->json(['error'=>'no subs'], 404);

        $payload = [
            'title' => 'DEBUG',
            'body'  => 'Push immédiat depuis le serveur',
            'data'  => ['url' => '/', 'reminderId' => 0],
            'actions' => [
                ['action'=>'open','title'=>'Ouvrir'],
                ['action'=>'done','title'=>'Fait'],
                ['action'=>'snooze','title'=>'Plus tard'],
            ],
            'tag' => 'debug-'.time(), 'renotify' => true, 'requireInteraction' => true
        ];

        $reports = $this->push->sendWithReport($subs, $payload); // cf. étape 3
        return $this->json(['reports'=>$reports]);
    }

}

<?php

namespace App\Controller;

use App\Entity\NotificationPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PreferencesController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/api/preferences', methods: ['GET'])]
    public function getMe(): JsonResponse
    {
        $u = $this->getUser();
        $pref = $u->getNotificationPreference() ?? (new NotificationPreference())->setUser($u);
        if (!$u->getNotificationPreference()) {
            $this->em->persist($pref);
            $this->em->flush();
        }
        return $this->json(['newChallenge' => $pref->isNewChallenge()]);
    }

    #[Route('/api/preferences', methods: ['POST'])]
    public function update(Request $r): JsonResponse
    {
        $u = $this->getUser();
        $data = json_decode($r->getContent(), true) ?? [];
        $pref = $u->getNotificationPreference() ?? (new NotificationPreference())->setUser($u);
        if (isset($data['newChallenge'])) $pref->setNewChallenge((bool)$data['newChallenge']);
        $this->em->persist($pref);
        $this->em->flush();
        return $this->json(['ok' => true, 'newChallenge' => $pref->isNewChallenge()]);
    }
}

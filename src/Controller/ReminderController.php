<?php

namespace App\Controller;

use App\Entity\Progression;
use App\Repository\ProgressionRepository;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Reminder;

final class ReminderController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param Request $req
     * @param ProgressionRepository $progressionRepository
     * @return JsonResponse
     * @throws Exception
     */
    #[Route('/api/reminders', methods: ['POST'])]
    public function create(Request $req, ProgressionRepository $progressionRepository): JsonResponse
    {
        $u = $this->getUser();
        $data = json_decode($req->getContent(), true) ?? [];

        $progression = null;

        if (isset($data['progressionId'])) {
            $progression = $this->em->getRepository(Progression::class)->find((int)$data['progressionId']);
        }

        if (!$progression || $progression->getUser() !== $u) {
            return $this->json(['error'=>'Progression not found'], 404);
        }

        // Empêcher doublon
        $existing = $this->em->getRepository(Reminder::class)->findOneBy(['progression'=>$progression, 'active'=>true]);
        if ($existing) return $this->json(['id'=>$existing->getId(), 'status'=>'exists'], 200);

        try { $tz = new \DateTimeZone($data['timezone'] ?? 'Europe/Paris'); }
        catch (\Throwable) { return $this->json(['error'=>'Invalid timezone'], 400); }

        try { $whenLocal = new \DateTimeImmutable($data['scheduledAt'], $tz); }
        catch (\Throwable) { return $this->json(['error'=>'Invalid scheduledAt'], 400); }

        $whenUtc = $whenLocal->setTimezone(new \DateTimeZone('UTC'));

        $rem = (new Reminder())
            ->setProgression($progression)
            ->setScheduledAtUtc($whenUtc)
            ->setTimezone($tz->getName())
            ->setRecurrence($data['recurrence'] ?? 'NONE')
            ->setActive(true);

        $this->em->persist($rem);
        $this->em->flush();

        return $this->json(['id'=>$rem->getId()], 201);
    }

    /**
     * @param Reminder $rem
     * @return JsonResponse
     */
    #[Route('/api/reminders/{id}/complete', methods: ['POST'])]
    public function complete(Reminder $rem): JsonResponse {
        if ($rem->getProgression()->getUser() !== $this->getUser()) return $this->json(['error'=>'Forbidden'],403);

        if ($rem->getRecurrence()==='DAILY') {
            $rem->setScheduledAtUtc($rem->getScheduledAtUtc()->add(new \DateInterval('P1D')));
        } elseif ($rem->getRecurrence()==='WEEKLY') {
            $rem->setScheduledAtUtc($rem->getScheduledAtUtc()->add(new \DateInterval('P1W')));
        } else {
            $this->em->remove($rem);
        }
        $this->em->flush();
        return $this->json(['ok'=>true]);
    }

    /**
     * @param Reminder $rem
     * @return JsonResponse
     * @throws Exception
     */
    #[Route('/api/reminders/{id}/snooze', methods: ['POST'])]
    public function snooze(Reminder $rem): JsonResponse {
        if ($rem->getProgression()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $next = $rem->getScheduledAtUtc()->add(new \DateInterval('PT10M'));
        $rem->setScheduledAtUtc($next);

        $this->em->flush();

        return $this->json([
            'id' => $rem->getId(),
            'scheduledAtUtc' => $next->format(DATE_ATOM),
        ], Response::HTTP_OK);
    }
}

<?php

namespace App\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Enum\ChallengeStatus;
use App\Repository\ProgressionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;

final class ProgressionController extends AbstractController
{

    /**
     * Start challenge
     * @param Challenge $challenge
     * @param UserRepository $userRepo
     * @param EntityManagerInterface $entityManager
     * @param ProgressionRepository $progressionRepo
     * @return Response
     */
    #[Route('/api/progression/start/{id}', name: 'progression_start', methods: ['POST'])]
    public function startChallenge(
        Challenge              $challenge,
        UserRepository         $userRepo,
        EntityManagerInterface $entityManager,
        ProgressionRepository  $progressionRepo
    ): Response
    {
        $userInterface = $this->getUser();

        $user = $userRepo->findOneBy(['username' => $userInterface->getUserIdentifier()]);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable dans la base'], Response::HTTP_UNAUTHORIZED);
        }


        $existing = $progressionRepo->findOneBy([
            'user' => $user,
            'challenge' => $challenge,
        ]);

        if ($existing) {
            return $this->json(['message' => 'Défi déjà commencé'], Response::HTTP_OK);
        }

        $progression = new Progression();
        $progression->setUser($user);
        $progression->setChallenge($challenge);
        $progression->setStatus(ChallengeStatus::IN_PROGRESS);
        $progression->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($progression);
        $entityManager->flush();

        return $this->json(['message' => 'Défi commencé avec succès']);
    }

    /**
     * Remove challenge
     * @param Challenge $challenge
     * @param EntityManagerInterface $entityManager
     * @param ProgressionRepository $progressionRepo
     * @return Response
     */
    #[Route('/api/progression/remove/{id}', name: 'progression_remove', methods: ['DELETE'])]
    public function removeChallenge(
        Challenge              $challenge,
        EntityManagerInterface $entityManager,
        ProgressionRepository  $progressionRepo
    ): Response
    {
        $user = $this->getUser();

        $progression = $progressionRepo->findOneBy([
            'user' => $user,
            'challenge' => $challenge,
        ]);

        if (!$progression) {
            return $this->json(['message' => 'Défi non trouvé dans vos progressions'], Response::HTTP_NOT_FOUND);
        }

        $entityManager->remove($progression);
        $entityManager->flush();

        return $this->json(['message' => 'Défi supprimé de vos progressions']);
    }

    /**
     * Validate a challenge
     * @param Challenge $challenge
     * @param EntityManagerInterface $entityManager
     * @param ProgressionRepository $progressionRepo
     * @return Response
     */
    #[Route('/api/progression/validate/{id}', name: 'progression_validate', methods: ['POST'])]
    public function validateChallenge(
        Challenge              $challenge,
        EntityManagerInterface $entityManager,
        ProgressionRepository  $progressionRepo
    ): Response
    {
        $user = $this->getUser();

        $progression = $progressionRepo->findOneBy([
            'user' => $user,
            'challenge' => $challenge,
        ]);

        if (!$progression) {
            return $this->json(['message' => 'Défi non trouvé dans vos progressions'], Response::HTTP_NOT_FOUND);
        }

        $progression->setStatus(ChallengeStatus::COMPLETED);
        $progression->setCompletedAt(new \DateTimeImmutable());

        $entityManager->flush();

        return $this->json(['message' => 'Défi validé avec succès']);
    }

    /**
     * Modify the status of a progression by its ID
     * @param int $id
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @param ProgressionRepository $progressionRepo
     * @return Response
     */
    #[Route('/api/progression/status/{id}', name: 'progression_modify_status', methods: ['POST'])]
    public function modifyProgressionStatus(
        int                    $id,
        Request                $request,
        EntityManagerInterface $entityManager,
        ProgressionRepository  $progressionRepo
    ): Response
    {
        $user = $this->getUser();

        $progression = $progressionRepo->find($id);

        if (!$progression || $progression->getUser() !== $user) {
            return $this->json(['message' => 'Progression non trouvée ou accès refusé'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['status'])) {
            return $this->json(['message' => 'Le statut est obligatoire'], Response::HTTP_BAD_REQUEST);
        }

        $newStatus = $data['status'];

        $newStatusEnum = ChallengeStatus::tryFrom($newStatus);

        if ($newStatusEnum === null) {
            return $this->json(['message' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $progression->setStatus($newStatusEnum);

        if ($newStatusEnum === ChallengeStatus::COMPLETED) {
            $progression->setCompletedAt(new \DateTimeImmutable());
        } else {
            $progression->setCompletedAt(null);
        }

        $entityManager->flush();

        return $this->json(['message' => 'Statut de la progression mis à jour avec succès']);

    }


    /**
     * Get user's progression list
     * @param Request $request
     * @param ProgressionRepository $progressionRepo
     * @return Response
     */
    #[OA\Get(
        path: '/api/progression',
        description: 'Displays the challenges related to a user with optional filters by status and type.',
        summary: 'Lists the user’s challenges with filters',
        tags: ['Progression'],
        parameters: [
            new OA\QueryParameter(
                name: 'status',
                description: 'Challenge status (IN_PROGRESS, COMPLETED, PENDING, FAILED)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\QueryParameter(
                name: 'type',
                description: 'Type of challenge (ecological, sports, etc.)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of the user’s challenges',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: new Model(type: Progression::class))
                )
            ),
            new OA\Response(
                response: 401,
                description: 'User not authenticated'
            )
        ]
    )]
    #[Route('/api/progression', name: 'progression_list', methods: ['GET'])]
    public function listUserProgression(
        Request               $request,
        ProgressionRepository $progressionRepo
    ): Response
    {
        $user = $this->getUser();

        $status = $request->query->get('status'); // ex: "COMPLETED"
        $type = $request->query->get('category');     // ex: "ecologique"

        $progressions = $progressionRepo->findByUserWithFilters($user, $status, $type);

        $data = array_map(function (Progression $progression) {
            $r = $progression->getActiveReminder();
            return [
                'id' => $progression->getId(),
                'challengeId' => $progression->getChallenge()->getId(),
                'description' => $progression->getChallenge()->getDescription(),
                'name' => $progression->getChallenge()->getName(),
                'category' => $progression->getChallenge()->getCategory(),
                'status' => $progression->getStatus()->value,
                'startedAt' => $progression->getStartedAt()?->format('Y-m-d H:i:s'),
                'completedAt' => $progression->getCompletedAt()?->format('Y-m-d H:i:s'),
                'reminderId' => $r?->getId(),
                'nextReminderUtc' => $r?->getScheduledAtUtc()->format(DATE_ATOM),
                'recurrence'     => $r?->getRecurrence(),
                'timezone'       => $r?->getTimezone(),
            ];
        }, $progressions);

        return $this->json($data);
    }


}

<?php

namespace App\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Enum\ChallengeStatus;
use App\Repository\ProgressionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProgressionController extends AbstractController{


    #[Route('/api/progression/start/{id}', name: 'progression_start', methods: ['POST'])]
    public function startChallenge(
        Challenge $challenge,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        ProgressionRepository $progressionRepo
    ): Response {
        $userInterface = $this->getUser();

        if (!$userInterface) {
            return $this->json(['error' => 'Utilisateur non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $userRepo->findOneBy(['email' => $userInterface->getUserIdentifier()]);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable dans la base'], Response::HTTP_UNAUTHORIZED);
        }

        // Reste du code identique
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

        $em->persist($progression);
        $em->flush();

        return $this->json(['message' => 'Défi commencé avec succès']);
    }

    #[Route('/api/progression/remove/{id}', name: 'progression_remove', methods: ['DELETE'])]
    public function removeChallenge(
        Challenge $challenge,
        EntityManagerInterface $em,
        ProgressionRepository $progressionRepo
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $progression = $progressionRepo->findOneBy([
            'user' => $user,
            'challenge' => $challenge,
        ]);

        if (!$progression) {
            return $this->json(['message' => 'Défi non trouvé dans vos progressions'], Response::HTTP_NOT_FOUND);
        }

        $em->remove($progression);
        $em->flush();

        return $this->json(['message' => 'Défi supprimé de vos progressions']);
    }
}

<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\BadgeUnlockRepository;
use App\Repository\ProgressionRepository;
use App\Repository\XpLedgerRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

final class GamificationController extends AbstractController
{
    public function __construct(
        private XpLedgerRepository $ledger,
        private BadgeUnlockRepository $badgeUnlocks,
        private ProgressionRepository $progressions
    ) {}

    #[Route('/api/gamification/profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $xp   = $this->ledger->totalXp($user);

        $xpToReach = fn (int $lvl) => (int)round(100 * ($lvl ** 2));
        $level = 1; while ($xp >= $xpToReach($level + 1)) $level++;

        return $this->json([
            'xpTotal' => $xp,
            'level' => $level,
            'badges' => $this->badgeUnlocks->listForUser($user),
            'currentStreakDays' => $this->progressions->currentStreakDays($user),
        ]);
    }

    #[Route('/api/gamification/leaderboard', methods: ['GET'])]
    public function leaderboard(): JsonResponse
    {
        return $this->json(['items' => $this->ledger->leaderboardTop(100)], 200, [], []);
    }

    #[Route('/api/gamification/claim', name: 'api_gamification_claim', methods: ['POST'])]
    public function claim(Request $req): JsonResponse
    {
        /** @var \App\Entity\User $user */ $user = $this->getUser();
        $payload = json_decode($req->getContent() ?: '{}', true);
        $type = $payload['type'] ?? null;   // 'quest'
        $code = $payload['code'] ?? null;   // ex: 'W2025-35'

        if ($type !== 'quest' || !$code) {
            return $this->json(['error'=>'payload invalide'], 400);
        }

        // Déjà claim ?
        $reason = 'quest:'.$code;
        if ($this->ledger->hasReasonForUser($user, $reason)) {
            return $this->json(['status' => 'already_claimed']);
        }

        // === Condition métier : "au moins 3 défis complétés dans la semaine ISO du code" ===
        if (!preg_match('/^W(?P<year>\\d{4})-(?P<week>\\d{2})$/', $code, $m)) {
            return $this->json(['error' => 'code de quête invalide (attendu WYYYY-WW)'], 400);
        }
        $year = (int)$m['year'];
        $week = (int)$m['week'];

        $completed = $this->progressions->countCompletedInIsoWeek($user, $year, $week);
        $threshold = 3; // règle métier

        if ($completed < $threshold) {
            return $this->json([
                'status' => 'not_eligible',
                'need'   => $threshold,
                'have'   => $completed,
                'rule'   => '>= '.$threshold.' done in week '.$code,
            ], 403);
        }

        // OK → crédit
        $this->ledger->credit($user, 100, $reason); // 100 XP par défaut
        return $this->json(['status' => 'claimed', 'xp_credited' => 100]);
    }
}

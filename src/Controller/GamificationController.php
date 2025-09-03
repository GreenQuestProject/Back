<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\BadgeUnlockRepository;
use App\Repository\ProgressionRepository;
use App\Repository\XpLedgerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GamificationController extends AbstractController
{
    public function __construct(
        private XpLedgerRepository    $ledger,
        private BadgeUnlockRepository $badgeUnlocks,
        private ProgressionRepository $progressions
    )
    {
    }

    #[Route('/api/gamification/profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $xp = $this->ledger->totalXp($user);
        $xpToReach = fn(int $lvl) => (int)round(100 * ($lvl ** 2));
        $level = 1;
        while ($xp >= $xpToReach($level + 1)) $level++;

        $impact = $this->progressions->userImpact($user);

        return $this->json([
            'xpTotal' => $xp,
            'level' => $level,
            'badges' => $this->badgeUnlocks->listForUser($user),
            'currentStreakDays' => $this->progressions->currentStreakDays($user),
            'completedCount' => $impact['completedCount'],
            'impact' => [
                'co2Kg' => $impact['co2Kg'],
                'waterL' => $impact['waterL'],
                'wasteKg' => $impact['wasteKg'],
            ],
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
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($req->getContent() ?: '{}', true);
        $type = $payload['type'] ?? null;
        $code = $payload['code'] ?? null;

        if ($type !== 'quest' || !$code) {
            return $this->json(['error' => 'payload invalide'], 400);
        }

        $reason = 'quest:' . $code;
        if ($this->ledger->hasReasonForUser($user, $reason)) {
            return $this->json(['status' => 'already_claimed']);
        }

        if (!preg_match('/^W(?P<year>\\d{4})-(?P<week>\\d{2})$/', $code, $m)) {
            return $this->json(['error' => 'code de quête invalide (attendu WYYYY-WW)'], 400);
        }
        $year = (int)$m['year'];
        $week = (int)$m['week'];

        $completed = $this->progressions->countCompletedInIsoWeek($user, $year, $week);
        $threshold = 3;

        if ($completed < $threshold) {
            return $this->json([
                'status' => 'not_eligible',
                'need' => $threshold,
                'have' => $completed,
                'rule' => '>= ' . $threshold . ' done in week ' . $code,
            ], 403);
        }

        $this->ledger->credit($user, 100, $reason);
        return $this->json(['status' => 'claimed', 'xp_credited' => 100]);
    }
}

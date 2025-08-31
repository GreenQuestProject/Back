<?php

namespace App\Controller;

use App\Repository\ProgressionEventRepository;
use App\Repository\ProgressionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Request;


final class AnalyticsController extends AbstractController
{
    public function __construct(private ProgressionRepository $progressions,
                                private ProgressionEventRepository $events,
                                private UserRepository            $users) {}

    #[Route('api/analytics/overview', methods: ['GET'])]
    public function overview(Request $req): JsonResponse
    {
        $from = new \DateTimeImmutable($req->query->get('from', (new \DateTimeImmutable('-28 days'))->format('Y-m-d')));
        $to   = (new \DateTimeImmutable($req->query->get('to', (new \DateTimeImmutable('today'))->format('Y-m-d'))))->setTime(23,59,59);

        $completed = $this->progressions->countCompletedBetween($from, $to);
        $started   = $this->progressions->countStartedBetween($from, $to);
        $weekly    = $this->progressions->weeklyCompleted($from, $to);
        $cats      = $this->progressions->categoriesBreakdown($from, $to);
        $medianH   = $this->progressions->medianCompletionHours($from, $to);

        return $this->json([
            'completed' => $completed,
            'completionRate' => $started > 0 ? $completed / $started : 0,
            'medianHours' => $medianH,
            'weekly' => $weekly,
            'categories' => $cats,
        ]);
    }

    #[Route('api/analytics/funnel', name: 'api_analytics_funnel', methods: ['GET'])]
    public function funnel(Request $req): JsonResponse
    {
        $from = new \DateTimeImmutable($req->query->get('from', (new \DateTimeImmutable('-28 days'))->format('Y-m-d')));
        $to   = (new \DateTimeImmutable($req->query->get('to', (new \DateTimeImmutable('today'))->format('Y-m-d'))))->setTime(23,59,59);

        return $this->json($this->events->weeklyFunnel($from, $to));
    }

    #[Route('api/analytics/cohorts', name: 'api_analytics_cohorts', methods: ['GET'])]
    public function cohorts(Request $req): JsonResponse
    {
        $from = new \DateTimeImmutable($req->query->get('from', (new \DateTimeImmutable('-12 weeks'))->format('Y-m-d')));
        $to   = (new \DateTimeImmutable($req->query->get('to', (new \DateTimeImmutable('today'))->format('Y-m-d'))))->setTime(23,59,59);

        $signupWeek  = $this->users->signupsByWeek($from, $to);            // uid => W0
        $activeWeeks = $this->progressions->activeWeeksByUser($from, $to); // uid => [Wk=>true]

        // matrice de rétention (%)
        $signupCounts = [];
        $matrix = [];
        foreach ($signupWeek as $uid => $w0) {
            $signupCounts[$w0] = ($signupCounts[$w0] ?? 0) + 1;
            if (!isset($activeWeeks[$uid])) continue;
            foreach (array_keys($activeWeeks[$uid]) as $wk) {
                [$y0,$w0n] = sscanf($w0, '%d-W%d');
                [$y1,$w1n] = sscanf($wk, '%d-W%d');
                $idx = (($y1 - $y0) * 52) + ($w1n - $w0n);
                if ($idx < 0) continue;
                $matrix[$w0][$idx] = ($matrix[$w0][$idx] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($matrix as $w0 => $cols) {
            $row = ['signup_week'=>$w0];
            $base = $signupCounts[$w0] ?? 1;
            foreach ($cols as $idx => $count) $row['w'.$idx] = round(100 * $count / $base, 1);
            $out[] = $row;
        }
        usort($out, fn($a,$b)=>strcmp($a['signup_week'],$b['signup_week']));

        return $this->json(['cohorts'=>$out]);
    }
}

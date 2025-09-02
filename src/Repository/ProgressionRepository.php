<?php

namespace App\Repository;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Progression>
 */
class ProgressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Progression::class);
    }

    public function findByUserWithFilters($user, ?string $status, ?string $category): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.challenge', 'c')
            ->leftJoin('p.reminders', 'r', 'WITH', 'r.active = 1')
            ->addSelect('c', 'r')
            ->where('p.user = :user')
            ->setParameter('user', $user);

        if ($status) {
            $statusList = explode(',', $status);

            $enumStatus = array_filter(array_map(
                fn($stat) => ChallengeStatus::tryFrom(trim($stat)),
                $statusList
            ));

            if (!empty($enumStatus)) {
                $qb->andWhere('p.status IN (:statusList)')
                    ->setParameter('statusList', $enumStatus);
            }
        }

        if ($category) {
            $categories = explode(',', $category);

            $enumCategories = array_filter(array_map(
                fn($cat) => ChallengeCategory::tryFrom(trim($cat)),
                $categories
            ));

            if (!empty($enumCategories)) {
                $qb->andWhere('c.category IN (:categories)')
                    ->setParameter('categories', $enumCategories);
            }
        }

        return $qb->getQuery()->getResult();
    }

    public function countCompletedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int)$this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.status = :done')
            ->andWhere('p.completedAt BETWEEN :from AND :to')
            ->setParameter('done', ChallengeStatus::COMPLETED->value)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to',   $to,   Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
    }

    public function countStartedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int)$this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.status IN (:st)')
            ->andWhere('p.startedAt BETWEEN :from AND :to')
            ->setParameter('st', [
                ChallengeStatus::IN_PROGRESS->value,
                ChallengeStatus::COMPLETED->value,
            ], ArrayParameterType::STRING)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to',   $to,   Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array<int,array{x:string,y:int}> */
    public function weeklyCompleted(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.completedAt AS completedAt')
            ->andWhere('p.status = :done')
            ->andWhere('p.completedAt BETWEEN :from AND :to')
            ->setParameter('done', ChallengeStatus::COMPLETED->value)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to',   $to,   Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()->getArrayResult();

        $buckets = [];
        foreach ($rows as $r) {
            /** @var \DateTimeInterface|null $dt */
            $dt = $r['completedAt'];
            if (!$dt) continue;
            $key = $dt->format('o') . '-W' . $dt->format('W'); // ISO YYYY-Www
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }
        ksort($buckets);

        return array_map(fn($k,$v)=>['x'=>$k,'y'=>$v], array_keys($buckets), array_values($buckets));
    }

    /** @return array<int,array{key:string,done:int}> */
    public function categoriesBreakdown(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.challenge', 'c')
            ->select('c.category AS category')
            ->addSelect('COUNT(p.id) AS done')
            ->andWhere('p.status = :done')
            ->andWhere('p.completedAt BETWEEN :from AND :to')
            ->groupBy('c.category')
            ->orderBy('done', 'DESC')
            ->setParameter('done', ChallengeStatus::COMPLETED->value)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to',   $to,   Types::DATETIMETZ_IMMUTABLE);

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static function (array $r): array {
            $cat = $r['category'];

            if ($cat instanceof \BackedEnum) {
                $cat = $cat->value;
            } elseif ($cat instanceof \UnitEnum) {
                $cat = $cat->name;
            }
            return ['category' => (string)$cat, 'done' => (int)$r['done']];
        }, $rows);
    }


    public function medianCompletionHours(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.startedAt AS startedAt, p.completedAt AS completedAt')
            ->andWhere('p.status = :done')
            ->andWhere('p.startedAt IS NOT NULL')
            ->andWhere('p.completedAt BETWEEN :from AND :to')
            ->setParameter('done', ChallengeStatus::COMPLETED->value)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to',   $to,   Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()->getArrayResult();

        $hours = [];
        foreach ($rows as $r) {
            /** @var \DateTimeInterface|null $s */ $s = $r['startedAt'];
            /** @var \DateTimeInterface|null $c */ $c = $r['completedAt'];
            if (!$s || !$c) continue;
            $hours[] = max(0.0, ($c->getTimestamp() - $s->getTimestamp()) / 3600);
        }

        sort($hours);
        $n = count($hours);
        if ($n === 0) return 0.0;
        return $n % 2 ? (float)$hours[intdiv($n, 2)]
            : (float)(($hours[$n/2 - 1] + $hours[$n/2]) / 2);
    }

    /** Streak courante (jours consécutifs avec au moins un done) */
    public function currentStreakDays(User $user): int
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.completedAt AS completedAt')
            ->andWhere('p.user = :u')->setParameter('u', $user)
            ->andWhere('p.status = :done')->setParameter('done', ChallengeStatus::COMPLETED->value)
            ->andWhere('p.completedAt IS NOT NULL')
            ->orderBy('p.completedAt', 'DESC')
            ->getQuery()->getArrayResult();

        // normalise en dates (YYYY-MM-DD)
        $days = [];
        foreach ($rows as $r) {
            /** @var \DateTimeInterface $dt */
            $dt = $r['completedAt'];
            $days[$dt->format('Y-m-d')] = true;
        }

        $streak = 0;
        $cursor = (new \DateTimeImmutable('today'))->setTime(0, 0);
        while (isset($days[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }
        return $streak;
    }

    /** @return array<string,array<string,bool>> uid => ['YYYY-Www'=>true] */
    public function activeWeeksByUser(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.user) AS uid, p.startedAt AS startedAt, p.completedAt AS completedAt')
            ->andWhere('p.startedAt BETWEEN :from AND :to OR p.completedAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to',   $to)
            ->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $uid = (string)$r['uid'];
            foreach (['startedAt','completedAt'] as $k) {
                /** @var \DateTimeInterface|null $dt */ $dt = $r[$k];
                if (!$dt) continue;
                $wk = $dt->format('o').'-W'.$dt->format('W');
                $out[$uid][$wk] = true;
            }
        }
        return $out;
    }

    public function countCompletedInIsoWeek(User $user, int $isoYear, int $isoWeek): int
    {
        // Lundi 00:00 de la semaine ISO
        $start = (new \DateTimeImmutable())->setISODate($isoYear, $isoWeek, 1)->setTime(0, 0, 0);
        // Dimanche 23:59:59 de la même semaine
        $end   = $start->modify('+6 days')->setTime(23, 59, 59);

        return (int)$this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user = :u')->setParameter('u', $user)
            ->andWhere('p.status = :done')->setParameter('done', ChallengeStatus::COMPLETED->value)
            ->andWhere('p.completedAt BETWEEN :from AND :to')
            ->setParameter('from', $start, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to',   $end,   Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
    }

    public function userImpact(User $user): array
    {
        $row = $this->createQueryBuilder('p')
            ->select(
                'COALESCE(SUM(c.co2EstimateKg), 0) AS co2Kg',
                'COALESCE(SUM(c.waterEstimateL), 0) AS waterL',
                'COALESCE(SUM(c.wasteEstimateKg), 0) AS wasteKg',
                'COUNT(p.id) AS completedCount'
            )
            ->join('p.challenge', 'c')
            ->andWhere('p.user = :u')->setParameter('u', $user)
            ->andWhere('p.status = :s')->setParameter('s', ChallengeStatus::COMPLETED->value)
            ->getQuery()->getSingleResult();

        return [
            'completedCount' => (int)($row['completedCount'] ?? 0),
            'co2Kg'          => (float)($row['co2Kg'] ?? 0),
            'waterL'         => (float)($row['waterL'] ?? 0),
            'wasteKg'        => (float)($row['wasteKg'] ?? 0),
        ];
    }

    public function findInProgress(User $user, Challenge $challenge): ?Progression
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.challenge = :challenge')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('challenge', $challenge)
            ->setParameter('status', ChallengeStatus::IN_PROGRESS)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAccomplished(User $user, Challenge $challenge): ?Progression
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.challenge = :challenge')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('challenge', $challenge)
            ->setParameter('status', ChallengeStatus::COMPLETED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasInProgress(User $user, Challenge $challenge): bool
    {
        return (bool) $this->findInProgress($user, $challenge);
    }

    public function hasAccomplished(User $user, Challenge $challenge): bool
    {
        return (bool) $this->findAccomplished($user, $challenge);
    }

    public function hasCompleted(User $user, Challenge $challenge): bool
    {
        return (bool) $this->createQueryBuilder('p')
            ->select('1')
            ->andWhere('p.user = :user')
            ->andWhere('p.challenge = :challenge')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('challenge', $challenge)
            ->setParameter('status', ChallengeStatus::COMPLETED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasOtherInProgress(User $user, Challenge $challenge, int $excludeId): bool
    {
        return (bool) $this->createQueryBuilder('p')
            ->select('1')
            ->andWhere('p.user = :user')
            ->andWhere('p.challenge = :challenge')
            ->andWhere('p.status = :status')
            ->andWhere('p.id != :excludeId')
            ->setParameter('user', $user)
            ->setParameter('challenge', $challenge)
            ->setParameter('status', ChallengeStatus::IN_PROGRESS)
            ->setParameter('excludeId', $excludeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

//    /**
//     * @return Progression[] Returns an array of Progression objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('p.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Progression
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

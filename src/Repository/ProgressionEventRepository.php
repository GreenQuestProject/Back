<?php

namespace App\Repository;

use App\Entity\ProgressionEvent;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgressionEvent>
 */
class ProgressionEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgressionEvent::class);
    }

    /** @return array<int,array{week:string,viewed:int,started:int,done:int,abandoned:int}> */
    public function weeklyFunnel(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.eventType AS type, e.occurredAt AS at')
            ->andWhere('e.occurredAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()->getArrayResult();

        $buckets = [];
        foreach ($rows as $r) {
            /** @var DateTimeInterface $dt */
            $dt = $r['at'];
            if (!$dt) continue;
            $key = $dt->format('o') . '-W' . $dt->format('W');
            $buckets[$key] ??= ['viewed' => 0, 'started' => 0, 'done' => 0, 'abandoned' => 0];
            if (isset($buckets[$key][$r['type']])) $buckets[$key][$r['type']]++;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $week => $c) $out[] = ['week' => $week] + $c;
        return $out;
    }

//    /**
//     * @return ProgressionEvent[] Returns an array of ProgressionEvent objects
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

//    public function findOneBySomeField($value): ?ProgressionEvent
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

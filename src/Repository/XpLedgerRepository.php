<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\XpLedger;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<XpLedger>
 */
class XpLedgerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, XpLedger::class);
    }

    /**
     * @param User $user
     * @return int
     */
    public function totalXp(User $user): int
    {
        return (int)$this->createQueryBuilder('x')
            ->select('COALESCE(SUM(x.delta), 0)')
            ->andWhere('x.user = :u')->setParameter('u', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @param int $limit
     * @return array
     */
    /** @return array<int,array{username:string,xp_total:int}> */
    public function leaderboardTop(int $limit = 100): array
    {
        $rows = $this->createQueryBuilder('x')
            ->select('u.username AS username, COALESCE(SUM(x.delta), 0) AS xp_total')
            ->join('x.user', 'u')
            ->groupBy('u.id, u.username')
            ->orderBy('xp_total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        foreach ($rows as &$r) $r['xp_total'] = (int)$r['xp_total'];
        return $rows;
    }

    public function hasReasonForUser(User $user, string $reason): bool
    {
        $count = (int)$this->createQueryBuilder('x')
            ->select('COUNT(x.id)')
            ->andWhere('x.user = :u')->setParameter('u', $user)
            ->andWhere('x.reason = :r')->setParameter('r', $reason)
            ->getQuery()->getSingleScalarResult();

        return $count > 0;
    }

    public function credit(User $user, int $delta, string $reason, ?DateTimeImmutable $at = null): void
    {
        $ledger = new XpLedger();
        $ledger->setUser($user);
        $ledger->setDelta($delta);
        $ledger->setReason($reason);
        $ledger->setOccurredAt($at ?? new DateTimeImmutable());
        $em = $this->getEntityManager();
        $em->persist($ledger);
        $em->flush();
    }

//    /**
//     * @return XpLedger[] Returns an array of XpLedger objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('x')
//            ->andWhere('x.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('x.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?XpLedger
//    {
//        return $this->createQueryBuilder('x')
//            ->andWhere('x.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

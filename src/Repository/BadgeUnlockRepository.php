<?php

namespace App\Repository;

use App\Entity\BadgeUnlock;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BadgeUnlock>
 */
class BadgeUnlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BadgeUnlock::class);
    }

    /**
     * @param User $user
     * @return array
     */
    public function listForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('bu')
            ->select('b.code AS code, b.name AS name, b.rarity AS rarity, bu.unlockedAt AS unlockedAt')
            ->join('bu.badge', 'b')
            ->andWhere('bu.user = :u')->setParameter('u', $user)
            ->orderBy('bu.unlockedAt', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(function ($r) {
            $dt = $r['unlockedAt'];
            $iso = $dt instanceof \DateTimeInterface ? $dt->format(DATE_ATOM) : (string)$dt;
            return ['code'=>$r['code'], 'name'=>$r['name'], 'rarity'=>$r['rarity'], 'unlockedAt'=>$iso];
        }, $rows);
    }

//    /**
//     * @return BadgeUnlock[] Returns an array of BadgeUnlock objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('b')
//            ->andWhere('b.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('b.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?BadgeUnlock
//    {
//        return $this->createQueryBuilder('b')
//            ->andWhere('b.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

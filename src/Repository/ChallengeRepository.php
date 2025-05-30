<?php

namespace App\Repository;

use App\Entity\Challenge;
use App\Enum\ChallengeCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Challenge>
 */
class ChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Challenge::class);
    }

    /**
     * Find challenges with optional filters.
     *
     * @param string|null $category
     * @return Challenge[]
     */
    public function findWithFilters(?string $category = null): array
    {
        $qb = $this->createQueryBuilder('c');

        if ($category) {
            $enumCategory = ChallengeCategory::tryFrom($category);
            if ($enumCategory !== null) {
                $qb->andWhere('c.category = :category')
                   ->setParameter('category', $enumCategory);
            }
        }

        return $qb->getQuery()->getResult();
    }

//    /**
//     * @return Challenge[] Returns an array of Challenge objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('c.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Challenge
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

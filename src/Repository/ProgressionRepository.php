<?php

namespace App\Repository;

use App\Entity\Progression;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

<?php

namespace App\Repository;

use App\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Position>
 *
 * @method Position|null find($id, $lockMode = null, $lockVersion = null)
 * @method Position|null findOneBy(array $criteria, array $orderBy = null)
 * @method Position[]    findAll()
 * @method Position[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    public function save(Position $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Position $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countActivePositionsBySymbol(string $symbol): int
    {
        return (int) $this->createQueryBuilder('position')
            ->select('COUNT(position.id)')
            ->join('position.contract', 'contract')
            ->where('contract.symbol = :symbol')
            ->andWhere('position.status IN (:activeStates)')
            ->setParameter('symbol', strtoupper($symbol))
            ->setParameter('activeStates', [
                Position::STATE_PROPOSED,
                Position::STATE_ORDER_PENDING,
                Position::STATE_OPEN,
                Position::STATE_CLOSING_PENDING
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }
}

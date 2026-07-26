<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Repository;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Query\SkipLockedSqlWalker;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Webmozart\Assert\Assert;

/**
 * @template-extends EntityRepository<OutboxRecord>
 */
class OutboxRecordRepository extends EntityRepository
{
    /**
     * @param list<int> $recordIds
     */
    public function deleteRecords(array $recordIds): int
    {
        if ([] === $recordIds) {
            return 0;
        }

        $query = $this->createQueryBuilder('o')
            ->delete()
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $recordIds)
            ->getQuery()
        ;

        return (int) $query->execute();
    }

    /**
     * Confirms the relay: the domain event reached its transport, so the record counts as
     * published. Called from the messenger transport's ack, never before it.
     *
     * @param list<int> $recordIds
     */
    public function markRecordsPublished(array $recordIds, CarbonImmutable $publishedOn): int
    {
        if ([] === $recordIds) {
            return 0;
        }

        $query = $this->createQueryBuilder('o')
            ->update()
            ->set('o.publishedOn', ':publishedOn')
            ->set('o.claimedAt', 'NULL')
            ->where('o.id IN (:ids)')
            ->setParameter('publishedOn', $publishedOn)
            ->setParameter('ids', $recordIds)
            ->getQuery()
        ;

        return (int) $query->execute();
    }

    /**
     * Undoes a claim, making the records available again immediately rather than after the
     * lease expires. Used when a worker stops before relaying a whole claimed batch.
     *
     * @param list<int> $recordIds
     */
    public function releaseRecords(array $recordIds): int
    {
        if ([] === $recordIds) {
            return 0;
        }

        $query = $this->createQueryBuilder('o')
            ->update()
            ->set('o.claimedAt', 'NULL')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $recordIds)
            ->getQuery()
        ;

        return (int) $query->execute();
    }

    public function purgePublishedEvents(): void
    {
        $this->createQueryBuilder('o')
            ->delete()
            ->where('o.publishedOn IS NOT NULL')
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * @return list<OutboxRecord>
     */
    public function fetchAvailableRecordsForUpdate(
        int $limit,
        CarbonImmutable $leaseExpiredBefore,
        bool $skipLocked = false
    ): array {
        $query = $this->createAvailableMessagesQueryBuilder($leaseExpiredBefore)
            ->orderBy('o.occurredAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
        ;

        // use SELECT ... FOR UPDATE to lock the rows
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        if ($skipLocked) {
            $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, SkipLockedSqlWalker::class);
        }

        $records = $query->getResult();
        Assert::isArray($records);
        Assert::allIsInstanceOf($records, OutboxRecord::class);

        return array_values($records);
    }

    public function getRecordCount(CarbonImmutable $leaseExpiredBefore): int
    {
        return (int) $this->createAvailableMessagesQueryBuilder($leaseExpiredBefore)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * A record is available when it has not been published and is not currently claimed by a
     * live worker. A claim older than the lease is treated as abandoned, which is how records
     * survive a worker that died before it could confirm the relay.
     */
    public function createAvailableMessagesQueryBuilder(CarbonImmutable $leaseExpiredBefore): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->where('o.publishedOn IS NULL')
            // the parentheses are load bearing: without them the OR would swallow the ANDs
            ->andWhere('(o.claimedAt IS NULL OR o.claimedAt < :leaseExpiredBefore)')
            ->andWhere('o.occurredAt < :now')
            ->setParameter('leaseExpiredBefore', $leaseExpiredBefore)
            ->setParameter('now', CarbonImmutable::now())
            ;
    }
}

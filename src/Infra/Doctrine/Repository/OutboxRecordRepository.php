<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Repository;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Webmozart\Assert\Assert;

/**
 * @template-extends EntityRepository<OutboxRecord>
 */
class OutboxRecordRepository extends EntityRepository
{
    public function deleteRecord(int $recordId): int
    {
        $query = $this->createQueryBuilder('o')
            ->delete()
            ->where('o.id = :id')
            ->setParameter('id', $recordId)
            ->getQuery()
        ;

        return (int) $query->execute();
    }

    public function purgePublishedEvents(DateTimeInterface $before): void
    {
        $this->createQueryBuilder('o')
            ->delete()
            ->where('o.publishedOn IS NOT NULL')
            ->andWhere('o.publishedOn < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute()
        ;
    }

    public function fetchNextRecordForUpdate(): ?OutboxRecord
    {
        $query = $this->createAvailableMessagesQueryBuilder()
            ->orderBy('o.occurredAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
        ;

        // use SELECT ... FOR UPDATE to lock table
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        $record = $query->getOneOrNullResult();
        Assert::nullOrIsInstanceOf($record, OutboxRecord::class);

        return $record;
    }

    public function getRecordCount(): int
    {
        return (int) $this->createAvailableMessagesQueryBuilder()
            ->select('COUNT(o.id) as record_count')
            ->getQuery()
            ->setMaxResults(1)
            ->getSingleColumnResult()
        ;
    }

    public function createAvailableMessagesQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->where('o.publishedOn IS NULL')
            ->andWhere('o.occurredAt < :now')
            ->setParameter('now', CarbonImmutable::now())
            ;
    }
}

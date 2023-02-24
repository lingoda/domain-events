<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeInterface;
use DateInterval;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Event\PreAppendEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Implementation of outbox store with doctrine
 */
final class DoctrineOutboxStore implements OutboxStore
{
    private EntityManagerInterface $entityManager;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->entityManager = $entityManager;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function append(DomainEvent $domainEvent): void
    {
        $this->eventDispatcher->dispatch(new PreAppendEvent($domainEvent));

        $outboxRecord = new OutboxRecord(
            $domainEvent->getEntityId(),
            $domainEvent,
            $domainEvent->getOccurredAt()
        );

        $this->entityManager->persist($outboxRecord);
    }

    public function replace(DomainEvent $domainEvent): void
    {
        if ($domainEvent instanceof ReplaceableDomainEvent) {
            $repo = $this->entityManager->getRepository(OutboxRecord::class);
            $replaceableEvents = $repo->findBy([
                'entityId' => $domainEvent->getEntityId(),
                'eventType' => \get_class($domainEvent),
                'publishedOn' => null,
            ]);

            foreach ($replaceableEvents as $replaceableEvent) {
                $this->entityManager->remove($replaceableEvent);
            }
        }

        $this->append($domainEvent);
    }

    public function publish(OutboxRecord $outboxRecord): void
    {
        $outboxRecord->setPublishedOn(CarbonImmutable::now());

        $this->entityManager->persist($outboxRecord);
        $this->entityManager->flush();
    }

    public function purgePublishedEvents(DateTimeInterface|DateInterval|null $before = null): void
    {
        /**
         * @var OutboxRecordRepository $repo
         */
        $repo = $this->entityManager->getRepository(OutboxRecord::class);

        $before = match (true) {
            $before instanceof DateTimeInterface => $before,
            $before instanceof DateInterval => (new DateTime())->sub($before),
            !$before => new DateTime(),
        };

        $repo->purgePublishedEvents($before);
    }

    /**
     * @return iterable<OutboxRecord>
     */
    public function allUnpublished(): iterable
    {
        // to prevent CI failures
        $connection = $this->entityManager->getConnection();
        if (!$connection->isConnected()) {
            return [];
        }

        try {
            $schemaManager = $connection->getSchemaManager();
            if (!$schemaManager->tablesExist([OutboxRecord::TABLE_NAME])) {
                return [];
            }
        } catch (DBALException $e) {
            return [];
        }

        $now = new CarbonImmutable('+1 second');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(OutboxRecord::class, 'o')
            ->where('o.publishedOn IS NULL')
            ->andWhere('o.occurredAt < :now')
            ->orderBy('o.occurredAt')
            ->setParameter('now', $now)
        ;

        return $qb->getQuery()->toIterable();
    }
}

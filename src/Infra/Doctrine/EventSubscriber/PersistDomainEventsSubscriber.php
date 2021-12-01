<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Lingoda\DomainEventsBundle\Domain\Model\ContainsEvents;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;

/**
 * Doctrine entity listener stores recorded events in the outbox store
 */
final class PersistDomainEventsSubscriber implements EventSubscriber
{
    private OutboxStore $outboxStore;

    public function __construct(OutboxStore $outboxStore)
    {
        $this->outboxStore = $outboxStore;
    }

    public function getSubscribedEvents(): array
    {
        return [Events::preFlush];
    }

    public function preFlush(PreFlushEventArgs $eventArgs): void
    {
        $uow = $eventArgs->getEntityManager()->getUnitOfWork();

        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (!$entity instanceof ContainsEvents) {
                    continue;
                }

                $this->persistRecordedEvents($entity);
            }
        }
    }

    /**
     * Persists domain events triggered by Entities
     */
    private function persistRecordedEvents(ContainsEvents $entity): void
    {
        foreach ($entity->getRecordedEvents() as $domainEvent) {
            if ($domainEvent instanceof ReplaceableDomainEvent) {
                $this->outboxStore->replace($domainEvent);
            } else {
                $this->outboxStore->append($domainEvent);
            }
        }

        $entity->clearRecordedEvents();
    }
}

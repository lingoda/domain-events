<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEventDispatcher;
use Lingoda\DomainEventsBundle\Domain\Model\EventPublisher;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Symfony\Component\Lock\LockFactory;

/**
 * It locks events that are ready for publishing to make operation thread safe
 */
final class LockableEventPublisher implements EventPublisher
{
    private DomainEventDispatcher $domainEventDispatcher;
    private OutboxStore $outboxStore;
    private LockFactory $lockFactory;

    public function __construct(
        DomainEventDispatcher $domainEventDispatcher,
        OutboxStore $outboxStore,
        LockFactory $lockFactory
    ) {
        $this->domainEventDispatcher = $domainEventDispatcher;
        $this->outboxStore = $outboxStore;
        $this->lockFactory = $lockFactory;
    }

    public function publishDomainEvents(): void
    {
        foreach ($this->outboxStore->allUnpublished() as $event) {
            $this->publishEvent($event);
        }
    }

    private function publishEvent(OutboxRecord $outboxRecord): void
    {
        $lock = $this->lockFactory->createLock(
            sprintf('outbox-record-%d', $outboxRecord->getId())
        );

        if ($lock->acquire()) {
            $this->domainEventDispatcher->dispatch(
                $outboxRecord->getDomainEvent()
            );
            $this->outboxStore->publish($outboxRecord);

            $lock->release();
        }
    }
}

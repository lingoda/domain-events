<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Default implementation using event bus
 */
class DefaultDomainEventDispatcher implements DomainEventDispatcher
{
    private MessageBusInterface $eventBus;

    public function __construct(MessageBusInterface $eventBus)
    {
        $this->eventBus = $eventBus;
    }

    /**
     * @throws \RuntimeException
     */
    public function dispatch(DomainEvent $domainEvent): void
    {
        try {
            $this->eventBus->dispatch($domainEvent);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to dispatch domain event', 0, $e);
        }
    }
}

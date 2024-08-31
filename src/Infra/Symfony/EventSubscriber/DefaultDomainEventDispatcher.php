<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEventDispatcher;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
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
     * @throws ExceptionInterface
     */
    public function dispatch(DomainEvent $domainEvent): void
    {
        $this->eventBus->dispatch($domainEvent);
    }
}

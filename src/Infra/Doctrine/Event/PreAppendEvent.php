<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Event;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event is dispatched before domain event get stored, it can be used to enrich event
 */
final class PreAppendEvent extends Event
{
    private DomainEvent $domainEvent;

    public function __construct(DomainEvent $domainEvent)
    {
        $this->domainEvent = $domainEvent;
    }

    public function getDomainEvent(): DomainEvent
    {
        return $this->domainEvent;
    }
}

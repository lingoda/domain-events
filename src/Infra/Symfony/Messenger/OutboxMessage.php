<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;

class OutboxMessage
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

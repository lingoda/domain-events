<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;

class OutboxMessage
{
    private DomainEvent $domainEvent;
    private ?int $recordId;

    public function __construct(DomainEvent $domainEvent, ?int $recordId = null)
    {
        $this->domainEvent = $domainEvent;
        $this->recordId = $recordId;
    }

    public function getDomainEvent(): DomainEvent
    {
        return $this->domainEvent;
    }

    /**
     * Id of the outbox record this event came from. It survives a redelivery unchanged, so it
     * is the key consumers should deduplicate on.
     */
    public function getRecordId(): ?int
    {
        return $this->recordId;
    }
}

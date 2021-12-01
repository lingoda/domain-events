<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model\Traits;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;

trait EventRecorderTrait
{
    /**
     * @var DomainEvent[]
     */
    private array $recordedEvents = [];

    /**
     * @return DomainEvent[]
     */
    public function getRecordedEvents(): array
    {
        return $this->recordedEvents;
    }

    public function clearRecordedEvents(): void
    {
        $this->recordedEvents = [];
    }

    protected function recordEvent(DomainEvent $domainEvent): void
    {
        $this->recordedEvents[] = $domainEvent;
    }
}

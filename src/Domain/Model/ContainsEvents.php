<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

interface ContainsEvents
{
    /**
     * @return DomainEvent[]
     */
    public function getRecordedEvents(): array;

    public function clearRecordedEvents(): void;
}

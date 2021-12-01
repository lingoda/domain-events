<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model\Traits;

use Carbon\CarbonImmutable;

trait DomainEventTrait
{
    private string $entityId;
    private CarbonImmutable $occurredAt;

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getOccurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    protected function init(string $entityId): void
    {
        $this->entityId = $entityId;
        $this->occurredAt = CarbonImmutable::now();
    }
}

<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Lingoda\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;

final class ReplaceableTestDomainEvent implements ReplaceableDomainEvent
{
    public function __construct(private CarbonImmutable $occurredAt)
    {
    }

    public function getEntityId(): string
    {
        return 'entity-id';
    }

    public function getOccurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }
}

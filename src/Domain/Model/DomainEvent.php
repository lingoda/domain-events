<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

use Carbon\CarbonImmutable;

interface DomainEvent
{
    public function getEntityId(): string;

    public function getOccurredAt(): CarbonImmutable;
}

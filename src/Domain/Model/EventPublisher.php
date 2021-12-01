<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

interface EventPublisher
{
    public function publishDomainEvents(): void;
}

<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

interface DomainEventDispatcher
{
    public function dispatch(DomainEvent $domainEvent): void;
}

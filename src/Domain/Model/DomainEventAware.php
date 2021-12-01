<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

/**
 * Helper interface so we don't need to implement both interfaces all the time
 */
interface DomainEventAware extends ContainsEvents, RecordsEvents
{
}

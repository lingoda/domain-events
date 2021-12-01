<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

/**
 * Helper interface to implement domain event scheduling/re-scheduling
 *
 * We can use this interface to facilitate domain event scheduling in the future.
 * The domain event publisher published only events who's occurredAt date is <= NOW(), so we can schedule events
 * by setting occuredAt in the future, later on if the aggregateRoot changes and as a consequence we need to deleted
 * scheduled domain events and replace them with new dates this can help with that.
 */
interface ReplaceableDomainEvent extends DomainEvent
{
}

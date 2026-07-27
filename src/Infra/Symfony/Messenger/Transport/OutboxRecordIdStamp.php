<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the id of the outbox record a domain event was relayed from.
 *
 * Relaying is at least once - a worker killed between publishing an event and confirming it
 * will publish that event again - and the id is identical across those replays, so consumers
 * can use it to deduplicate. It is a plain stamp rather than a NonSendableStampInterface
 * precisely so that it is serialised into the transport headers and reaches them.
 */
final class OutboxRecordIdStamp implements StampInterface
{
    private int $recordId;

    public function __construct(int $recordId)
    {
        $this->recordId = $recordId;
    }

    public function getRecordId(): int
    {
        return $this->recordId;
    }
}

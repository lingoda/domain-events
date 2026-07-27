<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxRecordIdStamp;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

#[AsMessageHandler]
final class OutboxMessageHandler
{
    private RoutableMessageBus $routableMessageBus;
    private string $busName;

    public function __construct(RoutableMessageBus $routableMessageBus, string $busName)
    {
        $this->routableMessageBus = $routableMessageBus;
        $this->busName = $busName;
    }

    public function __invoke(OutboxMessage $outboxMessage): void
    {
        $stamps = [new BusNameStamp($this->busName)];

        $recordId = $outboxMessage->getRecordId();
        if (null !== $recordId) {
            // relaying is at least once, so give consumers a stable key to deduplicate on
            $stamps[] = new OutboxRecordIdStamp($recordId);
        }

        try {
            $this->routableMessageBus->dispatch(
                Envelope::wrap($outboxMessage->getDomainEvent())
                    ->with(...$stamps)
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to dispatch domain event', 0, $e);
        }
    }
}

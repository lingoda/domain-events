<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

final class OutboxMessageHandler implements MessageHandlerInterface
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
        $this->routableMessageBus->dispatch(
            Envelope::wrap($outboxMessage->getDomainEvent())
                ->with(
                    new BusNameStamp($this->busName)
                )
        );
    }
}

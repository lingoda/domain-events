<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
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

    /**
     * @throws ExceptionInterface
     */
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

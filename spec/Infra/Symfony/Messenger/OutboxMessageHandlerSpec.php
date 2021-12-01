<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessageHandler;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

class OutboxMessageHandlerSpec extends ObjectBehavior
{
    function let(RoutableMessageBus $bus)
    {
        $this->beConstructedWith($bus, 'bus-name');
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(OutboxMessageHandler::class);
    }

    function it_can_handle_outbox_message(
        RoutableMessageBus $bus,
        OutboxMessage $outboxMessage,
        DomainEvent $domainEvent
    ) {
        $outboxMessage->getDomainEvent()->willReturn($domainEvent);

        $bus
            ->dispatch(Argument::that(function (Envelope $envelope) use ($domainEvent) {
                return $envelope->getMessage() === $domainEvent->getWrappedObject()
                    && $envelope->last(BusNameStamp::class) instanceof BusNameStamp
                    && $envelope->last(BusNameStamp::class)->getBusName() === 'bus-name'
                ;
            }))
            ->willReturn(new Envelope($domainEvent->getWrappedObject()))
            ->shouldBeCalledOnce()
        ;

        $this($outboxMessage);
    }
}

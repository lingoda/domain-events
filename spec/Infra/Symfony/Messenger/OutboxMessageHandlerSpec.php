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
    function let(RoutableMessageBus $messageBus)
    {
        $this->beConstructedWith($messageBus, 'bus-name');
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(OutboxMessageHandler::class);
    }

    function it_can_handle_outbox_message(
        RoutableMessageBus $messageBus,
        OutboxMessage $outboxMessage,
        DomainEvent $domainEvent
    ) {
        $outboxMessage->getDomainEvent()->willReturn($domainEvent);

        $messageBus
            ->dispatch(Argument::that(function (Envelope $envelope) use ($domainEvent) {
                return $envelope->getMessage() === $domainEvent->getWrappedObject()
                    && $envelope->last(BusNameStamp::class) instanceof BusNameStamp
                    && $envelope->last(BusNameStamp::class)->getBusName() === 'bus-name'
                ;
            }))
            ->willReturn(new Envelope($domainEvent->getWrappedObject()))
            ->shouldBeCalledOnce()
        ;

        $this->__invoke($outboxMessage);
    }

    function it_fails_to_dispatch_domain_event(OutboxMessage $outboxMessage)
    {
        $this
            ->shouldThrow(new \RuntimeException('Failed to dispatch domain event'))
            ->during('__invoke', [$outboxMessage])
        ;
    }
}

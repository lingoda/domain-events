<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessageHandler;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxRecordIdStamp;
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
        $outboxMessage->getRecordId()->willReturn(42);

        $messageBus
            ->dispatch(Argument::that(function (Envelope $envelope) use ($domainEvent) {
                return $envelope->getMessage() === $domainEvent->getWrappedObject()
                    && $envelope->last(BusNameStamp::class) instanceof BusNameStamp
                    && $envelope->last(BusNameStamp::class)->getBusName() === 'bus-name'
                    // relaying is at least once, so the consumer needs a key to dedup on
                    && $envelope->last(OutboxRecordIdStamp::class) instanceof OutboxRecordIdStamp
                    && $envelope->last(OutboxRecordIdStamp::class)->getRecordId() === 42
                ;
            }))
            ->willReturn(new Envelope($domainEvent->getWrappedObject()))
            ->shouldBeCalledOnce()
        ;

        $this->__invoke($outboxMessage);
    }

    function it_omits_the_dedup_stamp_when_the_message_has_no_record_id(
        RoutableMessageBus $messageBus,
        OutboxMessage $outboxMessage,
        DomainEvent $domainEvent
    ) {
        $outboxMessage->getDomainEvent()->willReturn($domainEvent);
        $outboxMessage->getRecordId()->willReturn(null);

        $messageBus
            ->dispatch(Argument::that(
                fn (Envelope $envelope) => $envelope->last(OutboxRecordIdStamp::class) === null
            ))
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

<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\DefaultDomainEventDispatcher;
use PhpSpec\ObjectBehavior;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class DefaultDomainEventDispatcherSpec extends ObjectBehavior
{
    function let(MessageBusInterface $messageBus)
    {
        $this->beConstructedWith($messageBus);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(DefaultDomainEventDispatcher::class);
    }

    function it_can_dispatch_domain_event(DomainEvent $domainEvent, MessageBusInterface $messageBus)
    {
        $messageBus
            ->dispatch($domainEvent)
            ->willReturn(new Envelope($domainEvent->getWrappedObject()))
            ->shouldBeCalledOnce()
        ;

        $this->dispatch($domainEvent);
    }
}

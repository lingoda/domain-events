<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use PhpSpec\ObjectBehavior;

class OutboxMessageSpec extends ObjectBehavior
{
    function it_is_initializable(DomainEvent $domainEvent)
    {
        $this->beConstructedWith($domainEvent);
        $this->shouldHaveType(OutboxMessage::class);
        $this->getDomainEvent()->shouldBeEqualTo($domainEvent);
    }
}

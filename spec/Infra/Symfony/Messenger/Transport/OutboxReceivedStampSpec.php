<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxReceivedStamp;
use PhpSpec\ObjectBehavior;

class OutboxReceivedStampSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->beConstructedWith(10);
        $this->shouldHaveType(OutboxReceivedStamp::class);
        $this->getId()->shouldBeEqualTo(10);
    }
}

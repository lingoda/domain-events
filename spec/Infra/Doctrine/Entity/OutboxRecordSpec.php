<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Doctrine\Entity;

use Carbon\CarbonImmutable;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use PhpSpec\ObjectBehavior;

class OutboxRecordSpec extends ObjectBehavior
{
    function let(DomainEvent $domainEvent)
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->beConstructedWith('entity-id', $domainEvent, CarbonImmutable::now());
    }

    function letGo()
    {
        CarbonImmutable::setTestNow();
    }

    function it_is_initializable(DomainEvent $domainEvent)
    {
        $this->shouldHaveType(OutboxRecord::class);
        $this->getEntityId()->shouldBeEqualTo('entity-id');
        $this->getDomainEvent()->shouldBeEqualTo($domainEvent);
        $this->getOccurredAt()->eq(CarbonImmutable::now())->shouldBeEqualTo(true);
        $this->getPublishedOn()->shouldBeNull();
    }

    function it_can_be_published()
    {
        $tomorrow = new CarbonImmutable('tomorrow');
        $this->setPublishedOn($tomorrow);
        $this->getPublishedOn()->eq($tomorrow)->shouldBe(true);
    }
}

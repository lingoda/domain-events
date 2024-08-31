<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony;

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEventDispatcher;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Symfony\LockableEventPublisher;
use PhpSpec\ObjectBehavior;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class LockableEventPublisherSpec extends ObjectBehavior
{
    function let(
        DomainEventDispatcher $domainEventDispatcher,
        OutboxStore $outboxStore,
        LockFactory $lockFactory
    ) {
        $this->beConstructedWith($domainEventDispatcher, $outboxStore, $lockFactory);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(LockableEventPublisher::class);
    }

    function it_can_publish_domain_events(
        DomainEventDispatcher $domainEventDispatcher,
        OutboxStore $outboxStore,
        LockFactory $lockFactory,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent,
        SharedLockInterface $lock
    ) {
        $outboxRecord->getId()->willReturn(1);
        $outboxRecord->getDomainEvent()->willReturn($domainEvent);

        $outboxStore->allUnpublished()->willReturn([$outboxRecord]);

        $lockFactory
            ->createLock('outbox-record-1')
            ->willReturn($lock)
            ->shouldBeCalledTimes(2)
        ;

        $lock->acquire()->willReturn(true, false)->shouldBeCalledTimes(2);
        $lock->release()->shouldBeCalledOnce();

        $domainEventDispatcher->dispatch($domainEvent)->shouldBeCalledOnce();
        $outboxStore->publish($outboxRecord)->shouldBeCalledOnce();

        $this->publishDomainEvents(); // without lock
        $this->publishDomainEvents(); // already locked
    }
}

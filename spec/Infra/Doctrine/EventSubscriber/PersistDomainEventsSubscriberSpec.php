<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Lingoda\DomainEventsBundle\Domain\Model\ContainsEvents;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber\PersistDomainEventsSubscriber;
use PhpSpec\ObjectBehavior;

class PersistDomainEventsSubscriberSpec extends ObjectBehavior
{
    function let(OutboxStore $outboxStore)
    {
        $this->beConstructedWith($outboxStore);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(PersistDomainEventsSubscriber::class);
    }

    function it_listens_to_events()
    {
        $this->getSubscribedEvents()->shouldBeEqualTo([
            Events::preFlush,
        ]);
    }

    function it_can_persist_domain_events(
        OutboxStore $outboxStore,
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        PreFlushEventArgs $preFlushEventArgs,
        ContainsEvents $insertedEntity,
        ContainsEvents $updatedEntity,
        ContainsEvents $deletedEntity,
        DomainEvent $domainEvent,
        ReplaceableDomainEvent $replaceableDomainEvent
    ) {
        $unitOfWork->getIdentityMap()->willReturn([
            [
                $insertedEntity,
                new \stdClass(),
            ],
            [
                $updatedEntity,
            ],
            [
                $deletedEntity,
            ],
        ]);

        $entityManager->getUnitOfWork()->willReturn($unitOfWork);
        $preFlushEventArgs->getEntityManager()->willReturn($entityManager);

        $insertedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $insertedEntity->getRecordedEvents()->willReturn([$domainEvent, $replaceableDomainEvent]);

        $updatedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $updatedEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $deletedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $deletedEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $outboxStore->append($domainEvent)->shouldBeCalledTimes(3);
        $outboxStore->replace($replaceableDomainEvent)->shouldBeCalledOnce();

        $this->preFlush($preFlushEventArgs);
    }
}

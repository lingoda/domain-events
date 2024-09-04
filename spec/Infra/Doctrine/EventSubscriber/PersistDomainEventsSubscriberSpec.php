<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
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

    function it_can_persist_domain_events(
        OutboxStore $outboxStore,
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        PreFlushEventArgs $preFlushEventArgs,
        ContainsEvents $insertedEntity,
        ContainsEvents $updatedEntity,
        ContainsEvents $deletedEntity,
        ContainsEvents $scheduledInsertEntity,
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

        $unitOfWork->getScheduledEntityInsertions()->willReturn([
            123 => $scheduledInsertEntity,
        ]);

        $entityManager->getUnitOfWork()->willReturn($unitOfWork);
        $preFlushEventArgs->getObjectManager()->willReturn($entityManager);

        $insertedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $insertedEntity->getRecordedEvents()->willReturn([$domainEvent, $replaceableDomainEvent]);

        $updatedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $updatedEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $deletedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $deletedEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $scheduledInsertEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $scheduledInsertEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $outboxStore->append($domainEvent)->shouldBeCalledTimes(4);
        $outboxStore->replace($replaceableDomainEvent)->shouldBeCalledOnce();

        $this->preFlush($preFlushEventArgs);
    }

    function it_can_persist_identified_entities(
        OutboxStore $outboxStore,
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        PreFlushEventArgs $preFlushEventArgs,
        ContainsEvents $updatedEntity,
        DomainEvent $domainEvent
    ) {
        $unitOfWork->getIdentityMap()->willReturn([
            [
                $updatedEntity,
                new \stdClass(),
            ],
        ]);

        $unitOfWork->getScheduledEntityInsertions()->willReturn([]);

        $entityManager->getUnitOfWork()->willReturn($unitOfWork);
        $preFlushEventArgs->getObjectManager()->willReturn($entityManager);

        $updatedEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $updatedEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $outboxStore->append($domainEvent)->shouldBeCalledTimes(1);

        $this->preFlush($preFlushEventArgs);
    }

    function it_can_persist_entities_scheduled_for_insert(
        OutboxStore $outboxStore,
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        PreFlushEventArgs $preFlushEventArgs,
        ContainsEvents $scheduledInsertEntity,
        DomainEvent $domainEvent,
    ) {
        $unitOfWork->getIdentityMap()->willReturn([
            [
                new \stdClass(),
            ],
        ]);

        $unitOfWork->getScheduledEntityInsertions()->willReturn([
            123 => $scheduledInsertEntity,
        ]);

        $entityManager->getUnitOfWork()->willReturn($unitOfWork);
        $preFlushEventArgs->getObjectManager()->willReturn($entityManager);

        $scheduledInsertEntity->clearRecordedEvents()->shouldBeCalledOnce();
        $scheduledInsertEntity->getRecordedEvents()->willReturn([$domainEvent]);

        $outboxStore->append($domainEvent)->shouldBeCalledTimes(1);

        $this->preFlush($preFlushEventArgs);
    }
}

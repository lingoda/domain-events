<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Doctrine;

use Carbon\CarbonImmutable;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\UnitOfWork;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Event\PreAppendEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DoctrineOutboxStoreSpec extends ObjectBehavior
{
    function let(
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->beConstructedWith($entityManager, $eventDispatcher);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(DoctrineOutboxStore::class);
    }

    function it_can_append_domain_events(
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher,
        DomainEvent $domainEvent,
        UnitOfWork $unitOfWork
    ) {
        $now = CarbonImmutable::now();
        $domainEvent->getEntityId()->willReturn('entity-id');
        $domainEvent->getOccurredAt()->willReturn($now);

        $eventDispatcher->dispatch(Argument::type(PreAppendEvent::class))->shouldBeCalledOnce();

        $entityManager->getUnitOfWork()->willReturn($unitOfWork);
        $entityManager->persist(Argument::that(static function (OutboxRecord $outboxRecord) use ($now): bool {
            return $outboxRecord->getEntityId() === 'entity-id'
                && $outboxRecord->getOccurredAt()->eq($now)
                && $outboxRecord->getPublishedOn() === null;
        }))->shouldBeCalledOnce();

        $this->append($domainEvent);
    }

    function it_can_replace_domain_event(
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher,
        DomainEvent $domainEvent,
        ReplaceableDomainEvent $replaceableDomainEvent,
        UnitOfWork $unitOfWork,
        EntityRepository $repository,
        OutboxRecord $outboxRecord
    ) {
        // append call
        $now = CarbonImmutable::now();
        $domainEvent->getEntityId()->willReturn('entity-id');
        $domainEvent->getOccurredAt()->willReturn($now);

        $eventDispatcher->dispatch(Argument::type(PreAppendEvent::class))->shouldBeCalledTimes(2);

        $entityManager->getUnitOfWork()->willReturn($unitOfWork);
        $entityManager->persist(Argument::that(static function (OutboxRecord $outboxRecord) use ($now): bool {
            return \in_array($outboxRecord->getEntityId(), ['entity-id', 'replaceable-entity-id'], true)
                && $outboxRecord->getOccurredAt()->eq($now)
                && $outboxRecord->getPublishedOn() === null;
        }))->shouldBeCalledTimes(2);

        // replace call
        $replaceableDomainEvent->getEntityId()->willReturn('replaceable-entity-id');
        $replaceableDomainEvent->getOccurredAt()->willReturn($now)->shouldBeCalledOnce();

        $entityManager->getRepository(OutboxRecord::class)->willReturn($repository)->shouldBeCalledOnce();
        $repository
            ->findBy([
                'entityId' => 'replaceable-entity-id',
                'eventType' => \get_class($replaceableDomainEvent->getWrappedObject()),
                'publishedOn' => null,
            ])
            ->willReturn([$outboxRecord])
            ->shouldBeCalledOnce()
        ;

        $entityManager->remove($outboxRecord)->shouldBeCalledOnce(); // remove stored events that needs replacement

        $this->replace($domainEvent);
        $this->replace($replaceableDomainEvent);
    }

    function it_can_publish_stored_event(
        EntityManagerInterface $entityManager,
        OutboxRecord $storedEvent
    ) {
        $entityManager->persist($storedEvent)->shouldBeCalledOnce();
        $entityManager->flush()->shouldBeCalledOnce();

        $this->publish($storedEvent);
    }

    function it_can_purge_all_published_events(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepo
    ) {
        $entityManager->getRepository(OutboxRecord::class)->willReturn($outboxRecordRepo);
        $entityManager->getRepository(OutboxRecord::class)->shouldBeCalledOnce();

        $outboxRecordRepo->purgePublishedEvents(Argument::type(DateTime::class))->shouldBeCalledOnce();

        $this->purgePublishedEvents();
    }
}

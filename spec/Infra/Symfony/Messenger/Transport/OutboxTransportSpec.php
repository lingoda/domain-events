<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxReceivedStamp;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransport;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class OutboxTransportSpec extends ObjectBehavior
{
    function let(EntityManagerInterface $entityManager, OutboxRecordRepository $outboxRecordRepository)
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->beConstructedWith($entityManager);

        $entityManager->getRepository(OutboxRecord::class)->willReturn($outboxRecordRepository);
    }

    function letGo()
    {
        CarbonImmutable::setTestNow();
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(OutboxTransport::class);
    }

    function it_throws_exception_on_send()
    {
        $this->shouldThrow(TransportException::class)
            ->during('send', [new Envelope(new \stdClass())])
        ;
    }

    function it_can_reject_and_ack(
        OutboxRecordRepository $outboxRecordRepository,
        DomainEvent $domainEvent,
        EntityManagerInterface $entityManager
    ) {
        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->commit()->shouldBeCalledOnce();

        $envelope = Envelope::wrap($domainEvent->getWrappedObject())
            ->with(new OutboxReceivedStamp(1))
        ;

        $outboxRecordRepository->deleteRecord(1)->shouldBeCalledOnce();

        $this->reject($envelope);
        $this->ack($envelope);
    }

    function it_throws_exception_if_stamp_missing_during_reject_and_ack(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        DomainEvent $domainEvent
    ) {
        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->rollback()->shouldBeCalledOnce();

        $outboxRecordRepository->deleteRecord(Argument::cetera())->shouldNotBeCalled();

        $envelope = Envelope::wrap($domainEvent->getWrappedObject());

        $expectedException = new TransportException('No OutboxReceivedStamp found on the Envelope.');

        // reject
        $this->shouldThrow($expectedException)
            ->during('reject', [$envelope])
        ;

        // ack
        $this->shouldNotThrow($expectedException)
            ->during('ack', [$envelope])
        ;
    }

    function it_throws_transport_exception_during_db_error_during_reject_and_ack(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        DomainEvent $domainEvent
    ) {
        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->rollback()->shouldBeCalledOnce();

        $outboxRecordRepository->deleteRecord(Argument::cetera())->willThrow(DBALException::class);

        $envelope = Envelope::wrap($domainEvent->getWrappedObject())
            ->with(new OutboxReceivedStamp(1))
        ;

        // reject
        $this->shouldThrow(new TransportException(''))
            ->during('reject', [$envelope])
        ;

        // ack
        $this->shouldNotThrow(new TransportException(''))
            ->during('ack', [$envelope])
        ;
    }

    function it_can_get_record(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent
    ) {
        $entityManager->beginTransaction()->shouldBeCalledTimes(2);
        $entityManager->commit()->shouldBeCalledTimes(2);
        $entityManager->flush()->shouldBeCalledTimes(2);

        $outboxRecordRepository->fetchNextRecordForUpdate()
            ->willReturn(null, $outboxRecord->getWrappedObject())
            ->shouldBeCalled()
        ;

        // fetching empty database
        $this->get()->shouldBeEqualTo([]);

        // fetching a record
        $outboxRecord->getId()->willReturn(1)->shouldBeCalled();
        $outboxRecord->getDomainEvent()->willReturn($domainEvent)->shouldBeCalled();
        $outboxRecord
            ->setPublishedOn(Argument::that(fn (CarbonImmutable $now) => $now->eq(CarbonImmutable::now())))
            ->shouldBeCalledOnce()
        ;

        $records = $this->get();
        $records->shouldHaveCount(1);
        $records[0]->shouldBeOutboxEnvelope($outboxRecord);
    }

    /**
     * @return array<string, callable>
     */
    public function getMatchers(): array
    {
        return [
            'beOutboxEnvelope' => function ($subject, $record) {
                if (!$subject instanceof Envelope) {
                    return false;
                }

                $message = $subject->getMessage();
                if (!$message instanceof OutboxMessage) {
                    return false;
                }

                if ($message->getDomainEvent() !== $record->getDomainEvent()) {
                    return false;
                }

                $outboxReceivedStamp = $subject->last(OutboxReceivedStamp::class);
                if ($outboxReceivedStamp === null || $outboxReceivedStamp->getId() !== $record->getId()) {
                    return false;
                }

                $transportMessageIdStamp = $subject->last(TransportMessageIdStamp::class);
                if ($transportMessageIdStamp === null || $transportMessageIdStamp->getId() !== $record->getId()) {
                    return false;
                }

                return true;
            },
        ];
    }
}

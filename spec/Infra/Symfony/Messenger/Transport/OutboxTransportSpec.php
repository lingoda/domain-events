<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception\InvalidArgumentException as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxReceivedStamp;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransport;
use PhpSpec\ObjectBehavior;
use PhpSpec\Wrapper\Collaborator;
use Prophecy\Argument;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class OutboxTransportSpec extends ObjectBehavior
{
    function let(EntityManagerInterface $entityManager, OutboxRecordRepository $outboxRecordRepository)
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->beConstructedWith($entityManager);

        $entityManager->getRepository(OutboxRecord::class)->willReturn($outboxRecordRepository);

        // prophecy rejects a call to a double that has no matching prophecy at all,
        // so keep a permissive fallback for the writes; examples add the predictions
        $outboxRecordRepository->deleteRecords(Argument::cetera())->willReturn(0);
        $outboxRecordRepository->releaseRecords(Argument::cetera())->willReturn(0);
        $outboxRecordRepository->markRecordsPublished(Argument::cetera())->willReturn(0);
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

    function it_claims_one_record_at_a_time_by_default(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent
    ) {
        $this->prepareRecord($outboxRecord, 1, $domainEvent);

        $outboxRecordRepository->fetchAvailableRecordsForUpdate(1, $this->expiredLease(), false)
            ->willReturn([$outboxRecord->getWrappedObject()])
            ->shouldBeCalledOnce()
        ;

        // claiming only hides the record from other workers, it does not publish it
        $outboxRecord->setClaimedAt(Argument::that(fn (CarbonImmutable $at) => $at->eq(CarbonImmutable::now())))
            ->shouldBeCalledOnce()
        ;
        $outboxRecord->setPublishedOn(Argument::cetera())->shouldNotBeCalled();

        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->flush()->shouldBeCalledOnce();
        $entityManager->commit()->shouldBeCalledOnce();
        $entityManager->detach($outboxRecord->getWrappedObject())->shouldBeCalledOnce();

        $this->get()->shouldRelayOutboxEnvelopesFor([$outboxRecord]);
    }

    function it_does_no_writes_when_there_is_nothing_to_claim(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository
    ) {
        $outboxRecordRepository->fetchAvailableRecordsForUpdate(1, $this->expiredLease(), false)
            ->willReturn([])
            ->shouldBeCalledOnce()
        ;

        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->commit()->shouldBeCalledOnce();

        // an idle worker should not touch the records at all
        $entityManager->flush()->shouldNotBeCalled();
        $entityManager->detach(Argument::any())->shouldNotBeCalled();
        $outboxRecordRepository->releaseRecords(Argument::cetera())->shouldNotBeCalled();

        $this->get()->shouldBeEqualTo([]);
    }

    function it_publishes_nothing_until_the_worker_acknowledges_it(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent
    ) {
        $this->prepareRecord($outboxRecord, 1, $domainEvent);
        $this->stubClaim($entityManager, $outboxRecordRepository, 1, [$outboxRecord]);

        // relaying the whole batch without an ack must leave the record untouched: it was
        // handed to the worker but never confirmed as published
        $outboxRecordRepository->markRecordsPublished(Argument::cetera())->shouldNotBeCalled();
        $outboxRecordRepository->deleteRecords(Argument::cetera())->shouldNotBeCalled();
        $outboxRecordRepository->releaseRecords(Argument::cetera())->shouldNotBeCalled();

        $this->get()->shouldRelayOutboxEnvelopesFor([$outboxRecord]);
    }

    function it_marks_a_record_published_once_the_worker_acknowledges_it(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent
    ) {
        $this->prepareRecord($outboxRecord, 1, $domainEvent);
        $this->stubClaim($entityManager, $outboxRecordRepository, 1, [$outboxRecord]);

        $entityManager->beginTransaction()->shouldBeCalledTimes(2);
        $entityManager->commit()->shouldBeCalledTimes(2);
        $outboxRecordRepository
            ->markRecordsPublished([1], Argument::that(fn (CarbonImmutable $at) => $at->eq(CarbonImmutable::now())))
            ->willReturn(1)
            ->shouldBeCalledOnce()
        ;

        $envelopes = $this->get()->getWrappedObject();
        foreach ($envelopes as $envelope) {
            $this->ack($envelope);
        }
        unset($envelopes);
    }

    function it_deletes_an_acknowledged_record_when_pruning(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent
    ) {
        $this->beConstructedWith($entityManager, false, 1, true);

        $this->prepareRecord($outboxRecord, 1, $domainEvent);
        $this->stubClaim($entityManager, $outboxRecordRepository, 1, [$outboxRecord]);

        $outboxRecordRepository->deleteRecords([1])->willReturn(1)->shouldBeCalledOnce();
        $outboxRecordRepository->markRecordsPublished(Argument::cetera())->shouldNotBeCalled();

        $envelopes = $this->get()->getWrappedObject();
        foreach ($envelopes as $envelope) {
            $this->ack($envelope);
        }
        unset($envelopes);
    }

    function it_hands_back_the_records_it_never_relayed_when_the_worker_stops(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $firstRecord,
        OutboxRecord $secondRecord,
        OutboxRecord $thirdRecord,
        DomainEvent $firstEvent,
        DomainEvent $secondEvent,
        DomainEvent $thirdEvent
    ) {
        $this->beConstructedWith($entityManager, false, 3);

        $this->prepareRecord($firstRecord, 1, $firstEvent);
        $this->prepareRecord($secondRecord, 2, $secondEvent);
        $this->prepareRecord($thirdRecord, 3, $thirdEvent);
        $this->stubClaim($entityManager, $outboxRecordRepository, 3, [$firstRecord, $secondRecord, $thirdRecord]);

        // the one relayed record is published, the two the worker never got to give up their
        // claim - so a graceful stop neither loses nor replays anything
        $outboxRecordRepository->markRecordsPublished([1], Argument::any())->willReturn(1)->shouldBeCalledOnce();
        $outboxRecordRepository->releaseRecords([2, 3])->willReturn(2)->shouldBeCalledOnce();

        $envelopes = $this->get()->getWrappedObject();
        $this->ack($envelopes->current()); // the worker relays one message, then is told to stop
        unset($envelopes);
    }

    function it_confirms_every_ack_flush_records_instead_of_once_per_batch(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $firstRecord,
        OutboxRecord $secondRecord,
        DomainEvent $firstEvent,
        DomainEvent $secondEvent
    ) {
        // ack_flush = 1 caps replay after an abrupt crash at a single record
        $this->beConstructedWith($entityManager, false, 2, false, null, 300, 1);

        $this->prepareRecord($firstRecord, 1, $firstEvent);
        $this->prepareRecord($secondRecord, 2, $secondEvent);
        $this->stubClaim($entityManager, $outboxRecordRepository, 2, [$firstRecord, $secondRecord]);

        $outboxRecordRepository->markRecordsPublished([1], Argument::any())->willReturn(1)->shouldBeCalledOnce();
        $outboxRecordRepository->markRecordsPublished([2], Argument::any())->willReturn(1)->shouldBeCalledOnce();

        $envelopes = $this->get()->getWrappedObject();
        foreach ($envelopes as $envelope) {
            $this->ack($envelope);
        }
        unset($envelopes);
    }

    function it_keeps_the_claim_on_reject_so_the_lease_retries_it(
        OutboxRecordRepository $outboxRecordRepository,
        EntityManagerInterface $entityManager,
        DomainEvent $domainEvent
    ) {
        // deleting here would discard a domain event that was never published
        $outboxRecordRepository->deleteRecords(Argument::cetera())->shouldNotBeCalled();
        $outboxRecordRepository->markRecordsPublished(Argument::cetera())->shouldNotBeCalled();
        $outboxRecordRepository->releaseRecords(Argument::cetera())->shouldNotBeCalled();
        $entityManager->beginTransaction()->shouldNotBeCalled();

        $envelope = Envelope::wrap($domainEvent->getWrappedObject())
            ->with(new OutboxReceivedStamp(1))
        ;

        $this->reject($envelope);
    }

    function it_can_claim_with_skip_locked(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $outboxRecord,
        DomainEvent $domainEvent
    ) {
        $this->beConstructedWith($entityManager, true, 5);

        $this->prepareRecord($outboxRecord, 1, $domainEvent);

        $outboxRecordRepository->fetchAvailableRecordsForUpdate(5, $this->expiredLease(), true)
            ->willReturn([$outboxRecord->getWrappedObject()])
            ->shouldBeCalledOnce()
        ;

        $outboxRecord->setClaimedAt(Argument::cetera())->shouldBeCalledOnce();
        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->flush()->shouldBeCalledOnce();
        $entityManager->commit()->shouldBeCalledOnce();
        $entityManager->detach(Argument::any())->shouldBeCalledOnce();

        $this->get()->shouldRelayOutboxEnvelopesFor([$outboxRecord]);
    }

    function it_stamps_the_configured_consumer_bus_on_every_envelope(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository,
        OutboxRecord $firstRecord,
        OutboxRecord $secondRecord,
        DomainEvent $firstEvent,
        DomainEvent $secondEvent
    ) {
        $this->beConstructedWith($entityManager, false, 2, false, 'outbox.bus');

        $this->prepareRecord($firstRecord, 1, $firstEvent);
        $this->prepareRecord($secondRecord, 2, $secondEvent);
        $this->stubClaim($entityManager, $outboxRecordRepository, 2, [$firstRecord, $secondRecord]);

        $this->get()->shouldRelayOutboxEnvelopesFor([$firstRecord, $secondRecord], 'outbox.bus');
    }

    function it_rolls_back_and_throws_when_claiming_fails(
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository
    ) {
        $outboxRecordRepository->fetchAvailableRecordsForUpdate(Argument::cetera())->willThrow(DBALException::class);

        $entityManager->beginTransaction()->shouldBeCalledOnce();
        $entityManager->rollback()->shouldBeCalledOnce();
        $entityManager->commit()->shouldNotBeCalled();

        $this->shouldThrow(TransportException::class)->during('get');
    }

    function it_reports_the_backlog_size(OutboxRecordRepository $outboxRecordRepository)
    {
        $outboxRecordRepository->getRecordCount($this->expiredLease())->willReturn(371_000);

        $this->getMessageCount()->shouldBe(371_000);
    }

    /**
     * @return array<string, callable>
     */
    public function getMatchers(): array
    {
        $isEnvelopeFor = static function ($envelope, $record, $busName): bool {
            if (!$envelope instanceof Envelope) {
                return false;
            }

            $message = $envelope->getMessage();
            if (!$message instanceof OutboxMessage) {
                return false;
            }

            if ($message->getDomainEvent() !== $record->getDomainEvent()) {
                return false;
            }

            // the record id travels with the message so consumers can deduplicate replays
            if ($message->getRecordId() !== $record->getId()) {
                return false;
            }

            $outboxReceivedStamp = $envelope->last(OutboxReceivedStamp::class);
            if ($outboxReceivedStamp === null || $outboxReceivedStamp->getId() !== $record->getId()) {
                return false;
            }

            $transportMessageIdStamp = $envelope->last(TransportMessageIdStamp::class);
            if ($transportMessageIdStamp === null || $transportMessageIdStamp->getId() !== $record->getId()) {
                return false;
            }

            // $busName of null means the envelope must not be routed to a specific bus
            $busNameStamp = $envelope->last(BusNameStamp::class);
            if ($busName === null) {
                return $busNameStamp === null;
            }

            return $busNameStamp !== null && $busNameStamp->getBusName() === $busName;
        };

        return [
            // relaying is lazy, so this drains the generator - one call per get()
            'relayOutboxEnvelopesFor' => static function ($subject, array $records, ?string $busName = null) use ($isEnvelopeFor) {
                $envelopes = array_values(is_array($subject) ? $subject : iterator_to_array($subject));

                if (\count($envelopes) !== \count($records)) {
                    return false;
                }

                foreach ($envelopes as $index => $envelope) {
                    if (!$isEnvelopeFor($envelope, $records[$index], $busName)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    private function expiredLease(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds(300);
    }

    /**
     * Registers the uninteresting half of a claim. Prophecy rejects calls to a double that has
     * no matching prophecy at all, and void methods take predictions rather than willReturn().
     *
     * @param list<Collaborator> $records
     */
    private function stubClaim(
        Collaborator $entityManager,
        Collaborator $outboxRecordRepository,
        int $batchSize,
        array $records
    ): void {
        $outboxRecordRepository
            ->fetchAvailableRecordsForUpdate($batchSize, $this->expiredLease(), false)
            ->willReturn(array_map(static fn (Collaborator $record) => $record->getWrappedObject(), $records))
        ;

        foreach ($records as $record) {
            $record->setClaimedAt(Argument::cetera())->shouldBeCalledOnce();
        }

        $entityManager->beginTransaction()->shouldBeCalled();
        $entityManager->flush()->shouldBeCalled();
        $entityManager->commit()->shouldBeCalled();
        $entityManager->detach(Argument::any())->shouldBeCalled();
    }

    private function prepareRecord(Collaborator $record, int $id, Collaborator $domainEvent): void
    {
        $record->getId()->willReturn($id);
        $record->getDomainEvent()->willReturn($domainEvent);
    }
}

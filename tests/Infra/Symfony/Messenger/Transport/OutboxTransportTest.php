<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Infra\Symfony\Messenger\Transport;

use Carbon\CarbonImmutable;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxReceivedStamp;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransport;
use Lingoda\DomainEventsBundle\Tests\InMemoryDatabaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Drives a real transport against a real database, because the guarantees this transport makes
 * live in the seam between it and the repository: the specs check the choreography against
 * doubles, the repository tests check the SQL, and neither would notice if the two disagreed.
 *
 * Out of scope here:
 *  - sqlite emits no FOR UPDATE, so skip_locked is not exercised (see SkipLockedSqlWalkerTest)
 *    and real contention between workers still needs MySQL;
 *  - both "workers" share one connection, so this proves lease visibility, not concurrency;
 *  - nothing touches AMQP - ack() standing for "reached the broker" is a property of the worker
 *    plus SendMessageMiddleware, and is verified in the consuming application.
 */
final class OutboxTransportTest extends InMemoryDatabaseTestCase
{
    private const LEASE_SECONDS = 300;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::now());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function testClaimingHidesARecordWithoutPublishingIt(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        $envelopes = $this->claim($this->transport());
        $envelopes->current(); // start relaying, but never acknowledge

        $claimed = $this->reload($record->getId());
        self::assertNotNull($claimed->getClaimedAt(), 'the claim must be persisted');
        self::assertNull($claimed->getPublishedOn(), 'claiming is not publishing');

        // and it is invisible to another worker for the duration of the lease
        self::assertSame([], $this->relay($this->transport()));
    }

    public function testARecordIsPublishedOnlyOnceTheWorkerAcknowledgesIt(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertCount(1, $this->relay($this->transport()));

        $published = $this->reload($record->getId());
        self::assertNotNull($published->getPublishedOn());
        self::assertNull($published->getClaimedAt(), 'a published record holds no claim');
    }

    public function testAcknowledgingDeletesTheRecordWhenPruning(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertCount(1, $this->relay($this->transport(prune: true)));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(OutboxRecord::class, $record->getId()));
    }

    public function testAGracefulStopPublishesWhatItRelayedAndHandsBackTheRest(): void
    {
        $first = $this->persistRecord(CarbonImmutable::now()->subMinutes(3));
        $second = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $third = $this->persistRecord(CarbonImmutable::now()->subMinute());

        // the worker relays one of the three, then is told to stop
        self::assertSame([$first->getId()], $this->relayedIds($this->transport(batchSize: 3), stopAfter: 1));

        self::assertNotNull($this->reload($first->getId())->getPublishedOn());
        foreach ([$second, $third] as $handedBack) {
            $reloaded = $this->reload($handedBack->getId());
            self::assertNull($reloaded->getClaimedAt(), 'an unrelayed record must give up its claim');
            self::assertNull($reloaded->getPublishedOn());
        }

        // available again immediately, without waiting out the lease
        self::assertSame(
            [$second->getId(), $third->getId()],
            $this->relayedIds($this->transport(batchSize: 3)),
        );
    }

    public function testAKilledWorkerLosesNothingOnceItsLeaseExpires(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        // a generator that was never started runs no finally, so nothing is handed back -
        // exactly what happens when the process is killed outright
        $abandoned = $this->claim($this->transport());
        unset($abandoned);

        self::assertNotNull($this->reload($record->getId())->getClaimedAt());
        self::assertSame([], $this->relayedIds($this->transport()), 'still leased');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(self::LEASE_SECONDS + 1));

        self::assertSame([$record->getId()], $this->relayedIds($this->transport()));
        self::assertNotNull($this->reload($record->getId())->getPublishedOn());
    }

    public function testRejectingKeepsTheRecordForTheLeaseToRetry(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        $transport = $this->transport();
        $envelopes = $this->claim($transport);
        $transport->reject($envelopes->current()); // publishing failed
        unset($envelopes);

        // deleting here would discard an event that was never published
        $rejected = $this->reload($record->getId());
        self::assertNull($rejected->getPublishedOn());
        self::assertNotNull($rejected->getClaimedAt());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(self::LEASE_SECONDS + 1));

        self::assertSame([$record->getId()], $this->relayedIds($this->transport()));
    }

    public function testAckFlushPublishesEachRecordAsItIsAcknowledged(): void
    {
        $first = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $second = $this->persistRecord(CarbonImmutable::now()->subMinute());

        $transport = $this->transport(batchSize: 2, ackFlush: 1);
        $envelopes = $this->claim($transport);

        $transport->ack($envelopes->current());

        // confirmed before the batch finished, which is what caps replay after a crash
        self::assertNotNull($this->reload($first->getId())->getPublishedOn());
        self::assertNull($this->reload($second->getId())->getPublishedOn());

        unset($envelopes);
    }

    public function testMessageCountIgnoresClaimedRecordsUntilTheirLeaseExpires(): void
    {
        $this->persistRecord(CarbonImmutable::now()->subMinute());
        self::assertSame(1, $this->transport()->getMessageCount());

        $abandoned = $this->claim($this->transport());
        $abandoned->current();

        self::assertSame(0, $this->transport()->getMessageCount(), 'in flight, not backlog');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(self::LEASE_SECONDS + 1));

        self::assertSame(1, $this->transport()->getMessageCount(), 'abandoned, so backlog again');
    }

    public function testAClaimedBatchIsInvisibleToTheDirectEventPublisher(): void
    {
        $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $this->persistRecord(CarbonImmutable::now()->subMinute());

        $store = new DoctrineOutboxStore($this->entityManager, new EventDispatcher());
        self::assertCount(2, iterator_to_array($store->allUnpublished(), false));

        $envelopes = $this->claim($this->transport(batchSize: 2));
        $envelopes->current();

        // LockableEventPublisher drains allUnpublished() on WorkerMessageHandledEvent, which
        // the worker fires between relaying a message and acknowledging it. If it could see
        // the rest of the claimed batch it would publish every one of those events twice.
        self::assertSame([], iterator_to_array($store->allUnpublished(), false));

        unset($envelopes);
    }

    public function testTheRelayedEnvelopeCarriesTheRecordIdForDeduplication(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        $relayed = $this->relay($this->transport());
        $envelope = $relayed[0];

        $message = $envelope->getMessage();
        self::assertInstanceOf(OutboxMessage::class, $message);
        self::assertSame($record->getId(), $message->getRecordId());
        self::assertSame($record->getId(), $envelope->last(OutboxReceivedStamp::class)?->getId());
        self::assertSame($record->getId(), $envelope->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testEveryRecordIsRelayedExactlyOnceAcrossAStopAndARestart(): void
    {
        $seeded = [];
        for ($i = 10; $i > 0; $i--) {
            $seeded[] = $this->persistRecord(CarbonImmutable::now()->subMinutes($i))->getId();
        }

        $relayed = [];
        // a worker dies mid-batch, a replacement drains the rest
        $relayed[] = $this->relayedIds($this->transport(batchSize: 4), stopAfter: 3);
        while ([] !== $batch = $this->relayedIds($this->transport(batchSize: 4))) {
            $relayed[] = $batch;
        }
        $relayed = array_merge(...$relayed);

        self::assertSame($seeded, $relayed, 'every record relayed exactly once, in order');
        self::assertSame(0, $this->transport()->getMessageCount());
    }

    private function transport(
        int $batchSize = 1,
        bool $prune = false,
        int $ackFlush = 0
    ): OutboxTransport {
        return new OutboxTransport(
            $this->entityManager,
            false,
            $batchSize,
            $prune,
            null,
            self::LEASE_SECONDS,
            $ackFlush,
        );
    }

    /**
     * A non-empty claim is relayed lazily, which is what lets a stopping worker hand back the
     * records it never got to.
     */
    private function claim(OutboxTransport $transport): \Generator
    {
        $envelopes = $transport->get();
        self::assertInstanceOf(\Generator::class, $envelopes);

        return $envelopes;
    }

    /**
     * Mimics Worker::run(): relay and acknowledge one message at a time, and abandon the rest
     * of the batch the moment the worker is told to stop.
     *
     * @return list<Envelope>
     */
    private function relay(OutboxTransport $transport, ?int $stopAfter = null): array
    {
        $relayed = [];

        $envelopes = $transport->get();
        foreach ($envelopes as $envelope) {
            $relayed[] = $envelope;
            $transport->ack($envelope);

            if (null !== $stopAfter && \count($relayed) >= $stopAfter) {
                break;
            }
        }
        unset($envelopes); // destroying the generator settles the batch

        return $relayed;
    }

    /**
     * @return list<int>
     */
    private function relayedIds(OutboxTransport $transport, ?int $stopAfter = null): array
    {
        return array_map(
            function (Envelope $envelope): int {
                $stamp = $envelope->last(OutboxReceivedStamp::class);
                self::assertInstanceOf(OutboxReceivedStamp::class, $stamp);

                return $stamp->getId();
            },
            $this->relay($transport, $stopAfter),
        );
    }

    private function reload(int $recordId): OutboxRecord
    {
        $this->entityManager->clear();

        $record = $this->entityManager->find(OutboxRecord::class, $recordId);
        self::assertInstanceOf(OutboxRecord::class, $record);

        return $record;
    }
}

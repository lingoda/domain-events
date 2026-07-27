<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Relays outbox records to the message bus.
 *
 * A record is only ever recorded as published once the worker confirms the domain event was
 * handled - which, for an asynchronously routed event, means it reached the broker. Claiming
 * is separate and reversible: `claimedAt` hides a record from the other workers, a graceful
 * stop hands back whatever was not relayed, and a claim left behind by a worker that died
 * expires after the lease so the record is picked up again.
 *
 * Delivery is therefore at least once. A worker killed outright between publishing an event
 * and confirming it will relay that event again, so handlers must be idempotent; envelopes
 * carry the record id to deduplicate on.
 */
final class OutboxTransport implements TransportInterface, MessageCountAwareInterface
{
    private const MAX_RETRIES = 3;

    private int $retryingSafetyCounter = 0;
    private EntityManagerInterface $entityManager;
    private OutboxRecordRepository $outboxRecordRepo;
    private bool $skipLocked;
    private int $batchSize;
    private bool $prune;
    private ?string $consumerBus;
    private int $leaseSeconds;
    private int $ackFlush;

    /** @var list<int> */
    private array $pendingAcks = [];

    /**
     * @param int $batchSize    records claimed per transaction; raising it cuts commits by
     *                          roughly the same factor
     * @param bool $prune       delete confirmed records instead of stamping publishedOn
     * @param int $leaseSeconds how long a claim hides a record from other workers; must exceed
     *                          the time a full batch takes to relay
     * @param int $ackFlush     confirm every N records instead of once per batch; caps how many
     *                          records an abrupt crash can replay
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        bool $skipLocked = false,
        int $batchSize = 1,
        bool $prune = false,
        ?string $consumerBus = null,
        int $leaseSeconds = 300,
        int $ackFlush = 0
    ) {
        /** @var OutboxRecordRepository $outboxRecordRepo */
        $outboxRecordRepo = $entityManager->getRepository(OutboxRecord::class);

        $this->outboxRecordRepo = $outboxRecordRepo;
        $this->entityManager = $entityManager;
        $this->skipLocked = $skipLocked;
        $this->batchSize = $batchSize;
        $this->prune = $prune;
        $this->consumerBus = $consumerBus;
        $this->leaseSeconds = $leaseSeconds;
        $this->ackFlush = $ackFlush;
    }

    public function get(): iterable
    {
        $envelopes = $this->claim();

        return [] === $envelopes ? [] : $this->relay($envelopes);
    }

    /**
     * Confirms the relay. The worker calls this only after the message bus handled the
     * envelope without throwing, so this is the first point at which the record is known to
     * have been published.
     */
    public function ack(Envelope $envelope): void
    {
        $outboxReceivedStamp = $envelope->last(OutboxReceivedStamp::class);
        if (!$outboxReceivedStamp instanceof OutboxReceivedStamp) {
            return;
        }

        $this->pendingAcks[] = $outboxReceivedStamp->getId();

        if ($this->ackFlush > 0 && \count($this->pendingAcks) >= $this->ackFlush) {
            $this->settle([]);
        }
    }

    /**
     * Deliberately does nothing. The record keeps its claim, so it becomes available again
     * once the lease expires - which retries it, and spaces the retries out. Deleting it here
     * (as this transport used to) discards a domain event that was never published.
     */
    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new TransportException('Send is not supported');
    }

    public function getMessageCount(): int
    {
        try {
            return $this->outboxRecordRepo->getRecordCount($this->leaseExpiredBefore());
        } catch (LogicException|DBALException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Claims a batch and returns one envelope per record, keyed by record id.
     *
     * @return array<int, Envelope>
     */
    private function claim(): array
    {
        $this->entityManager->beginTransaction();
        try {
            $records = $this->outboxRecordRepo->fetchAvailableRecordsForUpdate(
                $this->batchSize,
                $this->leaseExpiredBefore(),
                $this->skipLocked,
            );

            if ([] === $records) {
                $this->entityManager->commit();
                $this->retryingSafetyCounter = 0; // reset counter

                return [];
            }

            $envelopes = [];
            $claimedAt = CarbonImmutable::now();
            foreach ($records as $record) {
                $stamps = [
                    new OutboxReceivedStamp($record->getId()),
                    new TransportMessageIdStamp($record->getId()),
                ];

                if (null !== $this->consumerBus) {
                    // Route the message on a middleware-light bus without an app-side
                    // WorkerMessageReceivedEvent listener: the worker only *adds* stamps
                    // and RoutableMessageBus routes on the last BusNameStamp, so this one wins.
                    $stamps[] = new BusNameStamp($this->consumerBus);
                }

                $envelopes[$record->getId()] = Envelope::wrap(
                    new OutboxMessage($record->getDomainEvent(), $record->getId()),
                    $stamps,
                );

                // hide the record from the other workers, without claiming it was published
                $record->setClaimedAt($claimedAt);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
            $this->retryingSafetyCounter = 0; // reset counter
        } catch (DBALException\RetryableException $exception) {
            $this->entityManager->rollback();
            // Do nothing when RetryableException occurs less than "MAX_RETRIES"
            // as it will likely be resolved on the next call to get()
            // Problem with concurrent consumers and database deadlocks
            if (++$this->retryingSafetyCounter >= self::MAX_RETRIES) {
                $this->retryingSafetyCounter = 0; // reset counter
                throw new TransportException($exception->getMessage(), 0, $exception);
            }

            return [];
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        // the domain events are extracted, so nothing needs these entities anymore - keep the
        // identity map from growing over the worker's lifetime. detach() works on both ORM 2
        // and 3, unlike clear($entityName).
        foreach ($records as $record) {
            $this->entityManager->detach($record);
        }

        return $envelopes;
    }

    /**
     * Relays lazily so that the records a stopping worker never got to are still un-yielded
     * here and can be handed back: Worker::run() abandons the rest of the batch as soon as it
     * is told to stop, and PHP runs a generator's finally when it is destroyed while
     * suspended. Settling there is what makes a graceful stop lose nothing and replay nothing.
     *
     * @param array<int, Envelope> $envelopes
     */
    private function relay(array $envelopes): \Generator
    {
        $unrelayed = $envelopes;

        try {
            foreach ($envelopes as $recordId => $envelope) {
                unset($unrelayed[$recordId]);

                yield $envelope;
            }
        } finally {
            $this->settle(array_keys($unrelayed));
        }
    }

    /**
     * Writes the outcome of the batch in one transaction: records confirmed by ack() are
     * published (or deleted), records the worker never relayed give up their claim.
     *
     * @param list<int> $unrelayedIds
     */
    private function settle(array $unrelayedIds): void
    {
        $ackedIds = $this->pendingAcks;

        if ([] === $ackedIds && [] === $unrelayedIds) {
            return;
        }

        $this->entityManager->beginTransaction();
        try {
            if ([] !== $ackedIds) {
                if ($this->prune) {
                    $this->outboxRecordRepo->deleteRecords($ackedIds);
                } else {
                    $this->outboxRecordRepo->markRecordsPublished($ackedIds, CarbonImmutable::now());
                }
            }

            $this->outboxRecordRepo->releaseRecords($unrelayedIds);
            $this->entityManager->commit();

            $this->pendingAcks = [];
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            // keep the acks buffered: settling again later is a no-op if it already happened,
            // whereas dropping them would leave the records to be replayed after the lease
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    private function leaseExpiredBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds($this->leaseSeconds);
    }
}

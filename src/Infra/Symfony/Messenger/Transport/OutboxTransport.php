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
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Webmozart\Assert\Assert;

final class OutboxTransport implements TransportInterface, MessageCountAwareInterface
{
    private const MAX_RETRIES = 3;
    private int $retryingSafetyCounter = 0;
    private EntityManagerInterface $entityManager;
    private OutboxRecordRepository $outboxRecordRepo;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $outboxRecordRepo = $entityManager->getRepository(OutboxRecord::class);
        Assert::isInstanceOf($outboxRecordRepo, OutboxRecordRepository::class);

        $this->outboxRecordRepo = $outboxRecordRepo;
        $this->entityManager = $entityManager;
    }

    public function get(): iterable
    {
        $this->entityManager->beginTransaction();
        try {
            $outboxRecord = $this->outboxRecordRepo->fetchNextRecordForUpdate();
            if ($outboxRecord) {
                $outboxRecord->setPublishedOn(CarbonImmutable::now());
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

        if (!$outboxRecord) {
            return [];
        }

        return [
            Envelope::wrap(new OutboxMessage($outboxRecord->getDomainEvent()), [
                new OutboxReceivedStamp($outboxRecord->getId()),
                new TransportMessageIdStamp($outboxRecord->getId()),
            ]),
        ];
    }

    public function ack(Envelope $envelope): void
    {
        // do nothing, cleanup later
    }

    public function reject(Envelope $envelope): void
    {
        $this->deleteOutboxRecord($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new TransportException('Send is not supported');
    }

    public function getMessageCount(): int
    {
        try {
            return $this->outboxRecordRepo->getRecordCount();
        } catch (LogicException|DBALException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    private function deleteOutboxRecord(Envelope $envelope): void
    {
        $this->entityManager->beginTransaction();
        try {
            $outboxRecordId = $this->findOutboxReceivedStamp($envelope)->getId();
            $this->outboxRecordRepo->deleteRecord($outboxRecordId);
            $this->entityManager->commit();
        } catch (DBALException\RetryableException $exception) {
            $this->entityManager->rollback();
        } catch (LogicException|DBALException $exception) {
            $this->entityManager->rollback();
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws LogicException
     */
    private function findOutboxReceivedStamp(Envelope $envelope): OutboxReceivedStamp
    {
        /** @var OutboxReceivedStamp|null $outboxReceivedStamp */
        $outboxReceivedStamp = $envelope->last(OutboxReceivedStamp::class);

        if (null === $outboxReceivedStamp) {
            throw new LogicException('No OutboxReceivedStamp found on the Envelope.');
        }

        return $outboxReceivedStamp;
    }
}

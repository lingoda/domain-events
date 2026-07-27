<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Infra\Doctrine\Repository;

use Carbon\CarbonImmutable;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\Tests\InMemoryDatabaseTestCase;

final class OutboxRecordRepositoryTest extends InMemoryDatabaseTestCase
{
    private const LEASE_SECONDS = 300;

    private OutboxRecordRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = $this->entityManager->getRepository(OutboxRecord::class);
        \assert($repository instanceof OutboxRecordRepository);
        $this->repository = $repository;
    }

    public function testGetRecordCountReturnsRealNumberOfAvailableRecords(): void
    {
        // regression: the count used to be an (int) cast of getSingleColumnResult(),
        // i.e. of a non-empty array, which is always 1 regardless of the real count
        self::assertSame(0, $this->recordCount());

        $this->persistRecord(CarbonImmutable::now()->subMinutes(3));
        $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame(3, $this->recordCount());
    }

    public function testGetRecordCountIgnoresPublishedAndFutureRecords(): void
    {
        $published = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $published->setPublishedOn(CarbonImmutable::now());
        $this->entityManager->flush();

        $this->persistRecord(CarbonImmutable::now()->addHour());

        self::assertSame(0, $this->recordCount());

        $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame(1, $this->recordCount());
    }

    public function testFetchAvailableRecordsForUpdateClaimsTheOldestRecordsUpToTheLimit(): void
    {
        $oldest = $this->persistRecord(CarbonImmutable::now()->subMinutes(3));
        $middle = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame([$oldest, $middle], $this->claim(2));
    }

    public function testFetchAvailableRecordsForUpdateIgnoresPublishedAndFutureRecords(): void
    {
        $published = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $published->setPublishedOn(CarbonImmutable::now());
        $this->entityManager->flush();

        $this->persistRecord(CarbonImmutable::now()->addHour());
        $available = $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame([$available], $this->claim(10));
    }

    public function testFetchAvailableRecordsForUpdateReturnsNothingWhenTheOutboxIsEmpty(): void
    {
        self::assertSame([], $this->claim(10));
    }

    public function testAClaimedRecordIsHiddenFromOtherWorkersUntilItsLeaseExpires(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());

        // a live worker holds it
        $record->setClaimedAt(CarbonImmutable::now());
        $this->entityManager->flush();

        self::assertSame([], $this->claim(10));
        self::assertSame(0, $this->recordCount());

        // the worker died and the lease ran out
        $record->setClaimedAt(CarbonImmutable::now()->subSeconds(self::LEASE_SECONDS + 1));
        $this->entityManager->flush();

        self::assertSame([$record], $this->claim(10));
        self::assertSame(1, $this->recordCount());
    }

    public function testAnExpiredLeaseDoesNotResurrectAPublishedRecord(): void
    {
        $record = $this->persistRecord(CarbonImmutable::now()->subMinute());
        $record->setPublishedOn(CarbonImmutable::now());
        $record->setClaimedAt(CarbonImmutable::now()->subSeconds(self::LEASE_SECONDS + 1));
        $this->entityManager->flush();

        self::assertSame([], $this->claim(10));
        self::assertSame(0, $this->recordCount());
    }

    public function testMarkRecordsPublishedConfirmsTheRelayAndDropsTheClaim(): void
    {
        $confirmed = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $stillPending = $this->persistRecord(CarbonImmutable::now()->subMinute());
        $confirmed->setClaimedAt(CarbonImmutable::now());
        $this->entityManager->flush();

        $publishedOn = CarbonImmutable::now();
        self::assertSame(1, $this->repository->markRecordsPublished([$confirmed->getId()], $publishedOn));

        $this->entityManager->clear();
        self::assertSame([$stillPending->getId()], $this->claimedIds(10));

        $reloaded = $this->repository->find($confirmed->getId());
        \assert($reloaded instanceof OutboxRecord);
        self::assertNotNull($reloaded->getPublishedOn());
        self::assertNull($reloaded->getClaimedAt());
    }

    public function testReleaseRecordsMakesClaimedRecordsAvailableAgainImmediately(): void
    {
        $abandoned = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $stillHeld = $this->persistRecord(CarbonImmutable::now()->subMinute());
        $abandoned->setClaimedAt(CarbonImmutable::now());
        $stillHeld->setClaimedAt(CarbonImmutable::now());
        $this->entityManager->flush();

        self::assertSame(0, $this->recordCount());
        self::assertSame(1, $this->repository->releaseRecords([$abandoned->getId()]));

        // released without waiting for the lease, and only the one record
        $this->entityManager->clear();
        self::assertSame([$abandoned->getId()], $this->claimedIds(10));
    }

    public function testDeleteRecordsRemovesOnlyTheGivenIds(): void
    {
        $first = $this->persistRecord(CarbonImmutable::now()->subMinutes(3));
        $second = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame(2, $this->repository->deleteRecords([$first->getId(), $second->getId()]));
        self::assertSame(1, $this->recordCount());
    }

    /**
     * @dataProvider emptyIdListWriters
     */
    public function testWritesWithoutIdsDoNothing(string $method): void
    {
        $this->persistRecord(CarbonImmutable::now()->subMinute());

        // an empty IN () is not valid SQL, so the repository has to short-circuit
        self::assertSame(0, $this->repository->{$method}([]));
        self::assertSame(1, $this->recordCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyIdListWriters(): iterable
    {
        yield 'deleteRecords' => ['deleteRecords'];
        yield 'releaseRecords' => ['releaseRecords'];
    }

    private function recordCount(): int
    {
        return $this->repository->getRecordCount($this->leaseExpiredBefore());
    }

    /**
     * A pessimistic lock requires an open transaction, which is what the transport does
     * around the claim.
     *
     * @return list<OutboxRecord>
     */
    private function claim(int $limit): array
    {
        $this->entityManager->beginTransaction();
        $records = $this->repository->fetchAvailableRecordsForUpdate($limit, $this->leaseExpiredBefore());
        $this->entityManager->commit();

        return $records;
    }

    /**
     * @return list<int>
     */
    private function claimedIds(int $limit): array
    {
        return array_map(static fn (OutboxRecord $record) => $record->getId(), $this->claim($limit));
    }

    private function leaseExpiredBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds(self::LEASE_SECONDS);
    }

}

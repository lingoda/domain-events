<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Infra\Doctrine\Repository;

use Carbon\CarbonImmutable;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\Tests\Fixtures\RepositoryTestEvent;
use Lingoda\DomainEventsBundle\Tests\InMemoryDatabaseTestCase;

final class OutboxRecordRepositoryTest extends InMemoryDatabaseTestCase
{
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
        self::assertSame(0, $this->repository->getRecordCount());

        $this->persistRecord(CarbonImmutable::now()->subMinutes(3));
        $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame(3, $this->repository->getRecordCount());
    }

    public function testGetRecordCountIgnoresPublishedAndFutureRecords(): void
    {
        $published = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $published->setPublishedOn(CarbonImmutable::now());
        $this->entityManager->flush();

        $this->persistRecord(CarbonImmutable::now()->addHour());

        self::assertSame(0, $this->repository->getRecordCount());

        $this->persistRecord(CarbonImmutable::now()->subMinute());

        self::assertSame(1, $this->repository->getRecordCount());
    }

    private function persistRecord(CarbonImmutable $occurredAt): OutboxRecord
    {
        $record = new OutboxRecord('entity-id', new RepositoryTestEvent($occurredAt), $occurredAt);
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }
}

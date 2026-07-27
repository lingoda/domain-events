<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Infra\Doctrine;

use Carbon\CarbonImmutable;
use Lingoda\DomainEventsBundle\Domain\Model\ReplaceableDomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Tests\Fixtures\ReplaceableTestDomainEvent;
use Lingoda\DomainEventsBundle\Tests\InMemoryDatabaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * These cover the readers that used to lean on `publishedOn` doubling as an "someone is
 * handling this" marker. Since the transport publishes on ack, `claimedAt` is the only thing
 * that marks a record as taken, and anything acting on a claimed record races the transport.
 */
final class DoctrineOutboxStoreTest extends InMemoryDatabaseTestCase
{
    private DoctrineOutboxStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DoctrineOutboxStore($this->entityManager, new EventDispatcher());
    }

    public function testAllUnpublishedReturnsRecordsThatAreDue(): void
    {
        $due = $this->persistRecord(CarbonImmutable::now()->subMinute());
        $this->persistRecord(CarbonImmutable::now()->addHour());

        $published = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $published->setPublishedOn(CarbonImmutable::now());
        $this->entityManager->flush();

        self::assertSame([$due->getId()], $this->unpublishedIds());
    }

    public function testAllUnpublishedSkipsRecordsClaimedByATransport(): void
    {
        $claimed = $this->persistRecord(CarbonImmutable::now()->subMinutes(2));
        $free = $this->persistRecord(CarbonImmutable::now()->subMinute());

        $claimed->setClaimedAt(CarbonImmutable::now());
        $this->entityManager->flush();

        // publishing the claimed record here would emit its domain event a second time
        self::assertSame([$free->getId()], $this->unpublishedIds());
    }

    public function testReplaceRemovesAnUnclaimedRecordOfTheSameEntityAndType(): void
    {
        // removing an entity resets its identifier, so read it while it still has one
        $supersededId = $this->persistReplaceableRecord()->getId();

        $this->store->replace(new ReplaceableTestDomainEvent(CarbonImmutable::now()));
        $this->entityManager->flush();

        $ids = $this->allRecordIds();
        self::assertNotContains($supersededId, $ids);
        self::assertCount(1, $ids, 'the replacement was appended');
    }

    public function testReplaceLeavesARecordAClaimedTransportIsRelaying(): void
    {
        $inFlight = $this->persistReplaceableRecord();
        $inFlight->setClaimedAt(CarbonImmutable::now());
        $this->entityManager->flush();
        $inFlightId = $inFlight->getId();

        $this->store->replace(new ReplaceableTestDomainEvent(CarbonImmutable::now()));
        $this->entityManager->flush();

        // removing it would not stop the relay - the transport already holds the envelope - it
        // would only destroy the row the transport is about to confirm
        $ids = $this->allRecordIds();
        self::assertContains($inFlightId, $ids);
        self::assertCount(2, $ids, 'the replacement is appended alongside it');
    }

    private function persistReplaceableRecord(): OutboxRecord
    {
        $occurredAt = CarbonImmutable::now()->subMinute();
        $record = new OutboxRecord(
            'entity-id',
            new ReplaceableTestDomainEvent($occurredAt),
            $occurredAt,
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }

    /**
     * @return list<int>
     */
    private function unpublishedIds(): array
    {
        $ids = [];
        foreach ($this->store->allUnpublished() as $record) {
            $ids[] = $record->getId();
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function allRecordIds(): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn (OutboxRecord $record) => $record->getId(),
            $this->entityManager->getRepository(OutboxRecord::class)->findAll(),
        );
    }
}

<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Entity;

use Carbon\CarbonImmutable;
use Doctrine\ORM\Mapping as ORM;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: OutboxRecordRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Index(name: 'entity_type_published_idx', columns: ['entityId', 'eventType', 'publishedOn'])]
#[ORM\Index(name: 'idx_unpublished_occurred', columns: ['publishedOn', 'occurredAt'])]
#[ORM\Index(name: 'idx_unpublished_claimed_occurred', columns: ['publishedOn', 'claimedAt', 'occurredAt'])]
class OutboxRecord
{
    public const TABLE_NAME = 'outbox';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private int|string $id;

    #[ORM\Column(name: 'eventType', type: 'string', nullable: false)]
    private string $eventType;

    #[ORM\Column(name: 'domainEvent', type: 'byte_object', nullable: false)]
    private object $domainEvent;

    #[ORM\Column(name: 'entityId', type: 'string', length: 36, nullable: false)]
    private string $entityId;

    #[ORM\Column(name: 'occurredAt', type: 'carbon_immutable', nullable: false)]
    private CarbonImmutable $occurredAt;

    #[ORM\Column(name: 'publishedOn', type: 'carbon_immutable', nullable: true)]
    private ?CarbonImmutable $publishedOn;

    /**
     * When a messenger worker claimed this record for relaying. It is not a publication:
     * the record only counts as published once the worker confirms the domain event reached
     * its transport. A claim older than the transport's lease is treated as abandoned - the
     * worker died - and the record becomes available again.
     */
    #[ORM\Column(name: 'claimedAt', type: 'carbon_immutable', nullable: true)]
    private ?CarbonImmutable $claimedAt = null;

    public function __construct(
        string $entityId,
        DomainEvent $domainEvent,
        CarbonImmutable $occurredAt
    ) {
        $this->entityId = $entityId;
        $this->eventType = \get_class($domainEvent);
        $this->domainEvent = $domainEvent;
        $this->occurredAt = $occurredAt;
        $this->publishedOn = null;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getDomainEvent(): DomainEvent
    {
        Assert::isInstanceOf($this->domainEvent, DomainEvent::class);

        return $this->domainEvent;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getOccurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    public function getPublishedOn(): ?CarbonImmutable
    {
        return $this->publishedOn;
    }

    public function setPublishedOn(CarbonImmutable $publishedOn): void
    {
        $this->publishedOn = $publishedOn;
    }

    public function getClaimedAt(): ?CarbonImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?CarbonImmutable $claimedAt): void
    {
        $this->claimedAt = $claimedAt;
    }
}

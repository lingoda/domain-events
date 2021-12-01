<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Entity;

use Carbon\CarbonImmutable;
use Doctrine\ORM\Mapping as ORM;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;

/**
 * @ORM\Entity(repositoryClass="Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository")
 * @ORM\Table(name=self::TABLE_NAME, indexes={
 *     @ORM\Index(name="entity_type_published_idx", columns={"entityId", "eventType", "publishedOn"}),
 *     @ORM\Index(name="occurred_published_idx", columns={"occurredAt", "publishedOn"}),
 * })
 */
class OutboxRecord
{
    public const TABLE_NAME = 'outbox';

    /**
     * @ORM\Id
     * @ORM\Column(name="id", type="bigint")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $id;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    private string $eventType;

    /**
     * @ORM\Column(type="object", nullable=false)
     */
    private DomainEvent $domainEvent;

    /**
     * @ORM\Column(type="string", length=36, nullable=false)
     */
    private string $entityId;

    /**
     * @ORM\Column(type="carbon_immutable", nullable=false)
     */
    private CarbonImmutable $occurredAt;

    /**
     * @ORM\Column(type="carbon_immutable", nullable=true)
     */
    private ?CarbonImmutable $publishedOn;

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
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getDomainEvent(): DomainEvent
    {
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
}

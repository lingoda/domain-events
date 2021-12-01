<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Domain\Model;

interface ActorAwareEvent
{
    public function setActorId(?int $actorId): void;

    public function getActorId(): ?int;

    public function hasActorId(): bool;
}

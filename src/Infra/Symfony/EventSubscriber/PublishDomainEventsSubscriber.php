<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber;

use Lingoda\DomainEventsBundle\Domain\Model\EventPublisher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Publish domain events on
 *   http response termination
 *   console termination
 *   after message handling
 */
final class PublishDomainEventsSubscriber implements EventSubscriberInterface
{
    private EventPublisher $eventPublisher;
    private bool $enabled;

    public function __construct(
        EventPublisher $eventPublisher,
        bool $enabled
    ) {
        $this->eventPublisher = $eventPublisher;
        $this->enabled = $enabled;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'publishEventsFromHttp',
            ConsoleEvents::TERMINATE => 'publishEventsFromConsole',
            WorkerMessageHandledEvent::class => 'publishEventsFromWorker',
        ];
    }

    public function publishEventsFromHttp(TerminateEvent $event): void
    {
        $this->publishDomainEvents();
    }

    public function publishEventsFromConsole(ConsoleTerminateEvent $event): void
    {
        $this->publishDomainEvents();
    }

    public function publishEventsFromWorker(WorkerMessageHandledEvent $event): void
    {
        $this->publishDomainEvents();
    }

    private function publishDomainEvents(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->eventPublisher->publishDomainEvents();
    }
}

<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber\PersistDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\DefaultDomainEventDispatcher;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\PublishDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\LockableEventPublisher;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessageHandler;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransportFactory;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private();

    $services->alias('lingoda_domain_events.lock_factory_service', 'lock.factory');
    $services->alias('lingoda_domain_events.event_publisher', 'lingoda_domain_events.lockable_event_publisher');

    $services->set('lingoda_domain_events.domain_event_dispatcher_service', DefaultDomainEventDispatcher::class)
        ->args([service('messenger.bus.default')]);

    $services->set('lingoda_domain_events.event_subscriber.publisher', PublishDomainEventsSubscriber::class)
        ->args([
            service('lingoda_domain_events.event_publisher'),
            abstract_arg('enable_event_publisher, configured by the extension'),
        ])
        ->tag('kernel.event_subscriber');

    $services->set('lingoda_domain_events.event_subscriber.persister', PersistDomainEventsSubscriber::class)
        ->args([service('lingoda_domain_events.repository.outbox_store_doctrine')])
        ->tag('doctrine.event_listener', [
            'event' => 'preFlush',
            'connection' => 'default',
            'priority' => -1000,
        ]);

    $services->set('lingoda_domain_events.repository.outbox_store_doctrine', DoctrineOutboxStore::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('event_dispatcher'),
        ]);

    $services->set('lingoda_domain_events.lockable_event_publisher', LockableEventPublisher::class)
        ->args([
            service('lingoda_domain_events.domain_event_dispatcher_service'),
            service('lingoda_domain_events.repository.outbox_store_doctrine'),
            service('lingoda_domain_events.lock_factory_service'),
        ]);

    $services->alias(OutboxStore::class, 'lingoda_domain_events.repository.outbox_store_doctrine');

    $services->set('lingoda_domain_events.messenger.transport.outbox.factory', OutboxTransportFactory::class)
        ->args([service('doctrine')])
        ->tag('messenger.transport_factory');

    $services->set('lingoda_domain_events.outbox_message_handler', OutboxMessageHandler::class)
        ->args([
            service('messenger.routable_message_bus'),
            abstract_arg('message_bus_name, configured by the extension'),
        ])
        ->tag('messenger.message_handler');
};

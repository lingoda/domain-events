<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests;

use Doctrine\DBAL\Types\Type;
use Lingoda\DomainEventsBundle\DependencyInjection\LingodaDomainEventsExtension;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber\PersistDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Type\ByteObjectType;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\DefaultDomainEventDispatcher;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\PublishDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\LockableEventPublisher;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessageHandler;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransportFactory;
use Lingoda\DomainEventsBundle\LingodaDomainEventsBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleInitializationTest extends TestCase
{
    public function testExtensionRegistersExpectedDefinitions(): void
    {
        $container = $this->buildContainer();

        $expected = [
            'lingoda_domain_events.domain_event_dispatcher_service'   => DefaultDomainEventDispatcher::class,
            'lingoda_domain_events.event_subscriber.publisher'        => PublishDomainEventsSubscriber::class,
            'lingoda_domain_events.event_subscriber.persister'        => PersistDomainEventsSubscriber::class,
            'lingoda_domain_events.lockable_event_publisher'          => LockableEventPublisher::class,
            'lingoda_domain_events.repository.outbox_store_doctrine'  => DoctrineOutboxStore::class,
            'lingoda_domain_events.messenger.transport.outbox.factory' => OutboxTransportFactory::class,
            'lingoda_domain_events.outbox_message_handler'            => OutboxMessageHandler::class,
        ];

        foreach ($expected as $id => $class) {
            self::assertTrue($container->hasDefinition($id), "Missing definition: $id");
            self::assertSame($class, $container->getDefinition($id)->getClass());
        }
    }

    public function testExtensionRegistersExpectedAliases(): void
    {
        $container = $this->buildContainer();

        $aliases = [
            OutboxStore::class                              => 'lingoda_domain_events.repository.outbox_store_doctrine',
            'lingoda_domain_events.lock_factory_service'    => 'lock.factory',
            'lingoda_domain_events.event_publisher'         => 'lingoda_domain_events.lockable_event_publisher',
        ];

        foreach ($aliases as $alias => $target) {
            self::assertTrue($container->hasAlias($alias), "Missing alias: $alias");
            self::assertSame($target, (string) $container->getAlias($alias));
        }
    }

    public function testPersisterIsTaggedAsDoctrineEventListener(): void
    {
        $container = $this->buildContainer();

        self::assertSame(
            [['event' => 'preFlush', 'connection' => 'default', 'priority' => -1000]],
            $container->getDefinition('lingoda_domain_events.event_subscriber.persister')
                ->getTag('doctrine.event_listener'),
        );
    }

    public function testBundleConstructorRegistersByteObjectType(): void
    {
        new LingodaDomainEventsBundle();

        self::assertTrue(Type::hasType(ByteObjectType::TYPE));
        self::assertInstanceOf(ByteObjectType::class, Type::getType(ByteObjectType::TYPE));
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);

        (new LingodaDomainEventsExtension())->load([], $container);

        return $container;
    }
}

<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Dunglas\DoctrineJsonOdm\Bundle\DunglasDoctrineJsonOdmBundle;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEventDispatcher;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber\PersistDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\PublishDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\LockableEventPublisher;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessageHandler;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransportFactory;
use Lingoda\DomainEventsBundle\LingodaDomainEventsBundle;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class BundleInitializationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array{environment?: string, debug?: mixed} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        /** @var TestKernel $kernel */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(LingodaDomainEventsBundle::class);
        $kernel->addTestBundle(DoctrineBundle::class);
        $kernel->addTestBundle(DunglasDoctrineJsonOdmBundle::class);
        $kernel->addTestConfig(__DIR__ . '/config.yaml');
        $kernel->handleOptions($options);

        return $kernel;
    }

    public function testInitBundle(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        // Test if services exists
        $services = [
            'test.lingoda_domain_events.domain_event_dispatcher_service' => DomainEventDispatcher::class,
            'test.lingoda_domain_events.event_subscriber.publisher' => PublishDomainEventsSubscriber::class,
            'test.lingoda_domain_events.event_subscriber.persister' => PersistDomainEventsSubscriber::class,
            'test.lingoda_domain_events.lockable_event_publisher' => LockableEventPublisher::class,
            'test.lingoda_domain_events.messenger.transport.outbox.factory' => OutboxTransportFactory::class,
            'test.lingoda_domain_events.outbox_message_handler' => OutboxMessageHandler::class,
            'test.lingoda_domain_events.repository.outbox_store_doctrine' => DoctrineOutboxStore::class,
            'test.lingoda_domain_events.repository.outbox_store' => OutboxStore::class,
        ];

        foreach ($services as $id => $class) {
            self::assertTrue($container->has($id));
            $service = $container->get($id);
            self::assertInstanceOf($class, $service);
        }
    }
}

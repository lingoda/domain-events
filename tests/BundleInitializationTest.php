<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEventDispatcher;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\DoctrineOutboxStore;
use Lingoda\DomainEventsBundle\Infra\Doctrine\EventSubscriber\PersistDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\PublishDomainEventsSubscriber;
use Lingoda\DomainEventsBundle\Infra\Symfony\LockableEventPublisher;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessageHandler;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransportFactory;
use Lingoda\DomainEventsBundle\LingodaDomainEventsBundle;
use Nyholm\BundleTest\BaseBundleTestCase;
use Nyholm\BundleTest\CompilerPass\PublicServicePass;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

final class BundleInitializationTest extends BaseBundleTestCase
{
    /**
     * @return class-string
     */
    protected function getBundleClass(): string
    {
        return LingodaDomainEventsBundle::class;
    }

    public function testInitBundle(): void
    {
        // Boot the kernel.
        $this->bootCustomKernel();

        // Get the container
        $container = $this->getContainer();

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
            $this->assertTrue($container->has($id));
            $service = $container->get($id);
            $this->assertInstanceOf($class, $service);
        }
    }

    private function bootCustomKernel(): void
    {
        // Create a new Kernel
        $kernel = $this->createKernel();

        // Add some configuration
        $kernel->addConfigFile(__DIR__ . '/config.yaml');

        $this->addCompilerPass(new PublicServicePass('|lingoda_domain_events.*|'));

        // Add some other bundles we depend on
        $kernel->addBundle(FrameworkBundle::class);
        $kernel->addBundle(DoctrineBundle::class);

        // Boot the kernel as normal ...
        $this->bootKernel();
    }
}

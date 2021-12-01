<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class LingodaDomainEventsExtension extends Extension
{
    /**
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->useCustomMessageBusIfSpecified($config, $container);
        $this->configureEventPublishingSubscriber($config, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function useCustomMessageBusIfSpecified(array $config, ContainerBuilder $container): void
    {
        if (isset($config['message_bus_name'])) {
            $definition = $container->getDefinition('lingoda_domain_events.domain_event_dispatcher_service');
            $definition->replaceArgument(0, new Reference($config['message_bus_name']));

            $definition = $container->getDefinition('lingoda_domain_events.outbox_message_handler');
            $definition->replaceArgument(1, $config['message_bus_name']);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureEventPublishingSubscriber(array $config, ContainerBuilder $container): void
    {
        $enabled = true;
        if (isset($config['enable_event_publisher'])) {
            $enabled = $config['enable_event_publisher'];
        }

        $definition = $container->getDefinition('lingoda_domain_events.event_subscriber.publisher');
        $definition->replaceArgument(1, $enabled);
    }
}

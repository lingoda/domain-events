<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('lingoda_domain_events');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('message_bus_name')
                    ->defaultValue('messenger.bus.default')
                ->end()
                ->booleanNode('enable_event_publisher')->defaultValue(false)->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

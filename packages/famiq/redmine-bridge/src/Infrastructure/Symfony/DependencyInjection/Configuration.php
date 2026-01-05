<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('redmine_bridge');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('base_url')->isRequired()->end()
                ->scalarNode('api_key')->isRequired()->end()
                ->integerNode('project_id')->defaultValue(0)->end()
                ->integerNode('tracker_id')->defaultValue(0)->end()
                ->arrayNode('custom_fields')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('contacts_api_base')->defaultNull()->end()
                ->scalarNode('contacts_search_path')->defaultNull()->end()
                ->scalarNode('contacts_upsert_path')->defaultNull()->end()
                ->scalarNode('contact_strategy')->defaultValue('fallback')->end()
            ->end();

        return $treeBuilder;
    }
}

<?php

declare(strict_types=1);

namespace Helis\SettingsManagerBundle\DependencyInjection;

use Helis\SettingsManagerBundle\Model\DomainModel;
use Helis\SettingsManagerBundle\Model\Type;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('helis_settings_manager');
        $rootNode
            ->children()
                ->arrayNode('profiler')
                    ->canBeEnabled()
                    ->addDefaultsIfNotSet()
                ->end()
                ->arrayNode('logger')
                    ->addDefaultsIfNotSet()
                    ->canBeEnabled()
                    ->validate()
                        ->ifTrue(function ($v) {
                            return $v['enabled'] && !isset($v['service_id']);
                        })
                        ->thenInvalid('logger service_id is missing')
                    ->end()
                    ->children()
                        ->scalarNode('service_id')
                            ->example('monolog.logger.settings')
                            ->info('Psr\Log\LoggerInterface service id')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('access_control')
                    ->canBeEnabled()
                    ->addDefaultsIfNotSet()
                ->end()
                ->arrayNode('settings_config')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('lazy')
                            ->defaultTrue()
                            ->info('Settings from config will be lazy denormalized in provider.')
                        ->end()
                        ->integerNode('priority')
                            ->defaultValue(-10)
                            ->info('Priority for settings from configuration')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('settings_files')
                    ->scalarPrototype()->end()
                ->end()
                ->append($this->getSettingsNode())
            ->end();

        return $treeBuilder;
    }

    /**
     * @return NodeDefinition
     */
    private function getSettingsNode(): NodeDefinition
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('settings');
        $node
        ->arrayPrototype()
            ->children()
                ->scalarNode('name')->isRequired()->end()
                ->scalarNode('description')->end()
                ->arrayNode('domain')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function ($v) {
                            return [
                                'name' => $v,
                                'enabled' => true, // domains from config are enabled by default
                                'read_only' => true, // all config domains are read only
                            ];
                        })
                    ->end()
                    ->children()
                        ->scalarNode('name')->defaultValue(DomainModel::DEFAULT_NAME)->end()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('read_only')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('tags')
                    ->arrayPrototype()
                        ->beforeNormalization()
                        ->ifString()
                        ->then(function ($v) {
                            return ['name' => $v];
                        })
                        ->end()
                        ->children()
                            ->scalarNode('name')->end()
                        ->end()
                    ->end()
                ->end()
                ->enumNode('type')
                    ->values(array_values(Type::toArray()))
                    ->isRequired()
                ->end()
                ->arrayNode('data')
                    ->beforeNormalization()
                    ->always()
                    ->then(function ($v) {
                        if (is_string($v) || is_int($v) || is_float($v)) {
                            return ['value' => $v];
                        }

                        if (is_array($v) && isset($v['value'])) {
                            return $v;
                        }

                        return ['value' => $v];
                    })
                    ->end()
                    ->children()
                        ->variableNode('value')
                        ->isRequired()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $node;
    }
}

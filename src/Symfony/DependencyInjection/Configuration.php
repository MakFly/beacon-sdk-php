<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('beacon');
        $root = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line fluent config builder
        $root
            ->children()
                ->scalarNode('endpoint')->defaultValue('')->info('Beacon ingester base URL (empty = disabled)')->end()
                ->scalarNode('token')->defaultValue('')->info('Project API token (empty = disabled)')->end()
                ->scalarNode('service_name')->defaultValue('iautos-api')->end()
                ->scalarNode('service_version')->defaultNull()->end()
                ->scalarNode('stage')->defaultValue('%kernel.environment%')->end()
                ->scalarNode('application_path')->defaultValue('%kernel.project_dir%')->end()
                ->booleanNode('collect_arguments')->defaultFalse()->end()
                ->floatNode('traces_sample_rate')->defaultValue(1.0)->min(0.0)->max(1.0)->end()
                ->arrayNode('censor_keys')
                    ->scalarPrototype()->end()
                    ->defaultValue(['password', 'authorization', 'cookie', 'token', 'secret', 'api_key'])
                ->end()
            ->end();

        return $treeBuilder;
    }
}

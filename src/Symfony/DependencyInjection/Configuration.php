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
                ->booleanNode('capture_user')->defaultTrue()->info('Attach a pseudonymous authenticated user id to errors and root spans')->end()
                ->booleanNode('capture_monolog_exceptions')->defaultTrue()->info('Capture handled Throwables logged at ERROR+ as issues')->end()
                ->scalarNode('user_hash_key')->defaultNull()->info('Optional HMAC key; defaults to kernel.secret when available')->end()
                ->integerNode('max_backlog_items')->defaultValue(500)->min(1)->end()
                ->arrayNode('censor_keys')
                    ->scalarPrototype()->end()
                    ->defaultValue(['password', 'authorization', 'cookie', 'token', 'secret', 'api_key'])
                ->end()
            ->end();

        return $treeBuilder;
    }
}

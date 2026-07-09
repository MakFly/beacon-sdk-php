<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\DependencyInjection;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Config;
use KevStudios\Beacon\Symfony\EventSubscriber\ExceptionSubscriber;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use KevStudios\Beacon\Symfony\Monolog\BeaconHandler;
use KevStudios\Beacon\Transport\CurlSender;
use KevStudios\Beacon\Transport\SenderInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

final class BeaconExtension implements ExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), $configs);

        $resource = array_filter([
            'service.name' => $config['service_name'],
            'service.version' => $config['service_version'],
            'service.stage' => $config['stage'],
            'telemetry.sdk.name' => 'beacon-sdk-php',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => '0.3.0',
        ], static fn ($v) => $v !== null);

        $configDef = new Definition(Config::class, [
            '$resource' => $resource,
            '$censorKeys' => $config['censor_keys'],
            '$collectArguments' => $config['collect_arguments'],
            '$tracesSampleRate' => $config['traces_sample_rate'],
            '$applicationPath' => $config['application_path'],
        ]);
        $container->setDefinition('beacon.config', $configDef);

        $senderDef = new Definition(CurlSender::class, [
            '$endpoint' => $config['endpoint'],
            '$token' => $config['token'],
        ]);
        $container->setDefinition('beacon.sender', $senderDef);
        $container->setAlias(SenderInterface::class, 'beacon.sender');

        $beaconDef = new Definition(Beacon::class, [
            '$config' => new Reference('beacon.config'),
            '$sender' => new Reference('beacon.sender'),
        ]);
        $beaconDef->setPublic(true);
        $container->setDefinition(Beacon::class, $beaconDef);
        $container->setAlias('beacon', Beacon::class)->setPublic(true);

        $router = new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // Exception capture — auto-captures unhandled kernel exceptions.
        $exSub = new Definition(ExceptionSubscriber::class, [
            '$beacon' => new Reference(Beacon::class),
            '$router' => $router,
        ]);
        $exSub->addTag('kernel.event_subscriber');
        $container->setDefinition(ExceptionSubscriber::class, $exSub);

        // Request span — root HTTP span for every request (traces + performance).
        $reqSub = new Definition(RequestSpanSubscriber::class, [
            '$beacon' => new Reference(Beacon::class),
            '$router' => $router,
        ]);
        $reqSub->addTag('kernel.event_subscriber');
        $container->setDefinition(RequestSpanSubscriber::class, $reqSub);
        $container->setAlias('beacon.request_span', RequestSpanSubscriber::class);

        // Monolog handler — forward WARNING+ logs to the ingester.
        if (class_exists(\Monolog\Handler\AbstractProcessingHandler::class)) {
            $handlerDef = new Definition(BeaconHandler::class, [
                '$beacon' => new Reference(Beacon::class),
            ]);
            $container->setDefinition(BeaconHandler::class, $handlerDef);
            $container->setAlias('beacon.monolog_handler', BeaconHandler::class);
        }
    }

    public function getAlias(): string
    {
        return 'beacon';
    }

    public function getNamespace(): string
    {
        return 'http://kev-studios.iautos/schema/dic/beacon';
    }

    public function getXsdValidationBasePath(): string|false
    {
        return false;
    }
}

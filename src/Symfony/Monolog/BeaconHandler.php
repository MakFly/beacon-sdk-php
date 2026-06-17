<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\Monolog;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Protocol;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler that forwards log records to the Beacon ingester.
 * Configured in config/packages/monolog.yaml as a service handler.
 */
final class BeaconHandler extends AbstractProcessingHandler
{
    private const LEVEL_MAP = [
        'DEBUG' => Protocol::SEVERITY['DEBUG'],
        'INFO' => Protocol::SEVERITY['INFO'],
        'NOTICE' => Protocol::SEVERITY['INFO'],
        'WARNING' => Protocol::SEVERITY['WARN'],
        'ERROR' => Protocol::SEVERITY['ERROR'],
        'CRITICAL' => Protocol::SEVERITY['FATAL'],
        'ALERT' => Protocol::SEVERITY['FATAL'],
        'EMERGENCY' => Protocol::SEVERITY['FATAL'],
    ];

    public function __construct(
        private readonly Beacon $beacon,
        Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $levelName = $record->level->name;
        $severity = self::LEVEL_MAP[strtoupper($levelName)] ?? Protocol::SEVERITY['INFO'];

        $attributes = [];
        foreach ($record->context as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $attributes["context.$key"] = $value;
            }
        }
        $attributes['monolog.channel'] = $record->channel;

        $this->beacon->log(
            level: strtoupper($levelName),
            body: $record->message,
            attributes: $attributes,
        );
    }
}

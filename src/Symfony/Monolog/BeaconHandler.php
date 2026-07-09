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
 *
 * @deprecated Couples a telemetry HTTP call to the app request path (added latency, and a
 *   slow/down ingester back-pressures the app), and it cannot capture the "last breath"
 *   (fatals/OOM kill the process before it can POST). The supported path is now decoupled:
 *   the app logs JSON to stdout (12-factor) and the Beacon log-shipper (Fluent Bit, tailing
 *   the docker json-file) forwards it to /v1/logs out of band. Exceptions still surface as
 *   *issues* via the bundle's exception listener — that is unaffected. Kept for back-compat;
 *   do not wire it in new monolog configs.
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

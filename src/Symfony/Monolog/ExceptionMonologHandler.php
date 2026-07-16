<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony\Monolog;

use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Symfony\ExceptionCaptureRegistry;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Captures handled Throwables logged at ERROR+ as Beacon issues.
 *
 * Kernel exceptions are owned by ExceptionSubscriber and skipped here. Transport
 * failures are always swallowed because telemetry must never break the host app.
 */
final class ExceptionMonologHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Beacon $beacon,
        private readonly ExceptionCaptureRegistry $registry,
        private readonly bool $enabled = true,
        Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->enabled) {
            return;
        }

        $throwable = $this->findThrowable($record->context);
        if (!$throwable instanceof Throwable || !$this->registry->claimMonologException($throwable)) {
            return;
        }

        $attributes = [
            'monolog.channel' => $record->channel,
            'monolog.level' => $record->level->name,
            'monolog.message' => $record->message,
            'beacon.source' => 'monolog',
        ];

        foreach ($record->context as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $attributes["context.$key"] = $value;
            }
        }

        try {
            $this->beacon->captureException($throwable, handled: true, attributes: $attributes);
            // Workers do not dispatch kernel.terminate. Flush immediately so handled
            // exceptions are not held until a long-running process is restarted.
            $this->beacon->flush();
        } catch (Throwable) {
            // Best-effort telemetry: never fail the code path that emitted the log.
        }
    }

    private function findThrowable(mixed $value, int $depth = 0): ?Throwable
    {
        if ($value instanceof Throwable) {
            return $value;
        }

        if (!\is_array($value) || $depth >= 8) {
            return null;
        }

        foreach ($value as $nested) {
            $throwable = $this->findThrowable($nested, $depth + 1);
            if ($throwable instanceof Throwable) {
                return $throwable;
            }
        }

        return null;
    }
}

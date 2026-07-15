<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Transport;

/**
 * Transports a batch of payloads to one Beacon ingester endpoint. Implementations MUST
 * NOT throw into the host application — telemetry must never break the app.
 */
interface SenderInterface
{
    /**
     * @param 'errors'|'traces'|'logs' $endpoint
     * @param list<array<string, mixed>> $payloads
     */
    public function send(string $endpoint, array $payloads): bool;
}

<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Transport;

use KevStudios\Beacon\Protocol;

/**
 * Native cURL transport — zero external HTTP dependency. Sends newline-delimited JSON
 * (one payload per line) and swallows every failure so telemetry never breaks the host.
 */
final class CurlSender implements SenderInterface
{
    private readonly string $token;

    /**
     * @param string $endpoint Ingester base URL, e.g. https://beacon.iautos.fr (empty = disabled)
     * @param string $token    Project API token (empty = disabled)
     */
    public function __construct(
        private readonly string $endpoint,
        string $token,
        private readonly string $sdk = 'php/0.2.0',
        private readonly float $timeout = 2.0,
    ) {
        $this->token = preg_replace('/[\r\n]/', '', $token) ?? $token;
    }

    public function send(string $endpoint, array $payloads): void
    {
        if ($this->endpoint === '' || $payloads === [] || !\function_exists('curl_init')) {
            return;
        }

        $lines = [];
        foreach ($payloads as $payload) {
            $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false) {
                $lines[] = $json;
            }
        }
        if ($lines === []) {
            return;
        }

        $ch = curl_init(rtrim($this->endpoint, '/').'/v1/'.$endpoint);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => implode("\n", $lines),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            \CURLOPT_CONNECTTIMEOUT => (int) ceil($this->timeout),
            \CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-ndjson',
                'X-Beacon-Token: '.$this->token,
                'X-Beacon-Sdk: '.$this->sdk,
                'X-Beacon-Protocol: '.Protocol::VERSION,
            ],
        ]);

        // Errors are intentionally ignored; observability must be best-effort.
        @curl_exec($ch);
        curl_close($ch);
    }
}

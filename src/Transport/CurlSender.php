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
        private readonly string $sdk = 'php/0.5.0',
        private readonly float $timeout = 2.0,
        private readonly int $maxAttempts = 3,
        private readonly int $retryBaseDelayMs = 200,
        private readonly int $maxRetryDelayMs = 5000,
    ) {
        $this->token = preg_replace('/[\r\n]/', '', $token) ?? $token;
    }

    public function send(string $endpoint, array $payloads): bool
    {
        if ($this->endpoint === '' || $payloads === [] || !\function_exists('curl_init')) {
            return true;
        }

        $lines = [];
        foreach ($payloads as $payload) {
            $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false) {
                $lines[] = $json;
            }
        }
        if ($lines === []) {
            return true;
        }

        $body = implode("\n", $lines);
        $attempts = max(1, $this->maxAttempts);
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $retryAfter = null;
            $ch = curl_init(rtrim($this->endpoint, '/').'/v1/'.$endpoint);
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => $body,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT_MS => max(1, (int) round($this->timeout * 1000)),
                \CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($this->timeout * 1000)),
                \CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-ndjson',
                    'X-Beacon-Token: '.$this->token,
                    'X-Beacon-Sdk: '.$this->sdk,
                    'X-Beacon-Protocol: '.Protocol::VERSION,
                ],
                \CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfter): int {
                    if (stripos($header, 'Retry-After:') === 0) {
                        $retryAfter = trim(substr($header, \strlen('Retry-After:')));
                    }

                    return \strlen($header);
                },
            ]);

            $response = @curl_exec($ch);
            $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $status >= 200 && $status < 300) {
                return true;
            }
            if ($response !== false && !$this->retryable($status)) {
                return true;
            }
            if ($attempt < $attempts) {
                usleep($this->retryDelayMs($attempt, $retryAfter) * 1000);
            }
        }

        return false;
    }

    private function retryable(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    private function retryDelayMs(int $attempt, ?string $retryAfter): int
    {
        $explicit = $this->retryAfterMs($retryAfter);
        $exponential = max(0, $this->retryBaseDelayMs) * (2 ** max(0, $attempt - 1));
        $base = min(max(0, $this->maxRetryDelayMs), $explicit ?? $exponential);
        if ($base === 0) {
            return 0;
        }

        return min(
            max(0, $this->maxRetryDelayMs),
            random_int((int) floor($base / 2), (int) ceil($base * 1.5)),
        );
    }

    private function retryAfterMs(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value >= 0) {
            return (int) round((float) $value * 1000);
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, ($timestamp - time()) * 1000);
    }
}

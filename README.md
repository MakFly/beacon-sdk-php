# makfly/beacon-sdk-php

Beacon telemetry SDK for **PHP / Symfony 8** — errors, traces, logs. Home-grown,
**zero external instrumentation dependency** (no `open-telemetry/*`, no `sentry/*`).
Ships as a Symfony bundle; the PHP namespace is `KevStudios\Beacon\`.

## Install

```bash
composer require makfly/beacon-sdk-php
php vendor/makfly/beacon-sdk-php/bin/setup
```

The setup script auto-configures your Symfony project (idempotent, safe to re-run):

- `config/bundles.php` — registers `BeaconBundle` for all environments
- `config/packages/beacon.yaml` — creates the config with safe defaults
- `.env` / `.env.example` — appends `BEACON_ENDPOINT` and `BEACON_TOKEN`

**That's it.** By default the SDK is a silent no-op — zero network calls, zero overhead.
Set the env vars to activate:

```dotenv
# .env.local
BEACON_ENDPOINT=https://ingest.pulseview.app
BEACON_TOKEN=priv_my_project
```

The generated configuration uses `https://ingest.pulseview.app`. Override
`BEACON_ENDPOINT` for a self-hosted installation or an internal Docker address.
The empty token keeps the SDK disabled until a project credential is provided.

> Before the package is on Packagist, add a VCS repository:
> ```json
> "repositories": [{ "type": "vcs", "url": "https://github.com/MakFly/beacon-sdk-php.git" }]
> ```

## How it works

| Env vars set? | Behavior |
|---|---|
| Both set | Errors, traces and logs are sent to the ingester |
| Empty or absent | Silent no-op — no cURL, no overhead |

Unhandled kernel exceptions are captured automatically via `ExceptionSubscriber`.
The buffer flushes on `kernel.terminate` (post-response). The transport swallows
every failure — telemetry never breaks the host app.

HTTP exceptions are classified from the final response status: `4xx` occurrences stay
handled, while `5xx` occurrences are unhandled and force retention of their complete
request trace even when normal trace sampling would discard it. Errors carry the active
`traceId` and `spanId`, so the dashboard can open the exact failing trace and span.

When Symfony Security is available, authenticated users are counted through a stable
HMAC identifier (`usr_…`). The raw login/email is never sent. This is enabled by default
and uses `%kernel.secret%`; set `capture_user: false` to disable it or `user_hash_key` to
use a dedicated rotation-controlled key.

Transport attempts time out after 2 seconds and retry network failures, `408`, `429`
and `5xx` with exponential jitter while honoring `Retry-After`. Failed batches remain
in a bounded in-memory backlog (`max_backlog_items`, default 500). Trace sampling is
deterministic via `traces_sample_rate`, and sensitive keys are redacted recursively in
errors, spans and logs.
`W3CPropagator` injects and extracts `traceparent`, `tracestate` and `baggage` for
cross-service context propagation.

When Doctrine DBAL 4 is installed, the Symfony bundle automatically records queries,
prepared statements and `exec()` calls as `db_query` child spans. SQL literals are
replaced with placeholders before capture: the waterfall exposes the real duration
without leaking parameter values.

When Symfony's concrete PSR-18 client is registered, the bundle decorates it automatically
and records external calls as `http_client` child spans. Names use only the HTTP method and
host to stay low-cardinality. Paths, query strings, headers and request bodies are never
captured.

## Configuration

All options in `config/packages/beacon.yaml` (with defaults):

```yaml
parameters:
    # Default to empty STRING when the env var is unset (prod with no ingester yet).
    # Critical: CurlSender expects a `string` — '' constructs + silently no-ops, whereas
    # `null` (what `%env(default::...)%` returns) throws a TypeError at boot → 500 → rollback.
    env(BEACON_ENDPOINT): ''
    env(BEACON_TOKEN): ''

beacon:
    endpoint: '%env(BEACON_ENDPOINT)%'            # ingester URL (empty = disabled)
    token: '%env(BEACON_TOKEN)%'                  # project token (empty = disabled)
    service_name: 'iautos-api'                    # optional
    service_version: ~                            # optional (null)
    stage: '%kernel.environment%'                 # optional
    application_path: '%kernel.project_dir%'      # optional
    collect_arguments: false                      # opt-in: may contain sensitive values
    traces_sample_rate: 1.0                       # 0.0–1.0
    capture_user: true                            # pseudonymous affected-user identity
    user_hash_key: ~                              # defaults to %kernel.secret%
    max_backlog_items: 500                        # bounded in-memory retry backlog
    censor_keys:                                  # scrubbed from attributes
        - password
        - authorization
        - cookie
        - token
        - secret
        - api_key
```

## Versioning & release

SemVer via **git tags** (Packagist-driven). See [`CLAUDE.md`](./CLAUDE.md). Tags are immutable.

## License

MIT. Part of the [Beacon](https://github.com/MakFly) telemetry suite.

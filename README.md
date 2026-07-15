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
BEACON_ENDPOINT=https://beacon.example.com
BEACON_TOKEN=priv_my_project
```

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

Transport attempts time out after 2 seconds and retry network failures, `408`, `429`
and `5xx` with exponential jitter while honoring `Retry-After`. Failed batches remain
in a bounded in-memory backlog (`max_backlog_items`, default 500). Trace sampling is
deterministic via `traces_sample_rate`, and sensitive keys are redacted recursively in
errors, spans and logs.
`W3CPropagator` injects and extracts `traceparent`, `tracestate` and `baggage` for
cross-service context propagation.

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

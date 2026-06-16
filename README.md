# makfly/beacon-sdk-php

Beacon telemetry SDK for **PHP / Symfony 8** — errors, traces, logs. Home-grown,
**zero external instrumentation dependency** (no `open-telemetry/*`, no `sentry/*`).
Ships as a Symfony bundle; the PHP namespace is `KevStudios\Beacon\`.

```bash
composer require makfly/beacon-sdk-php
```

> Before the package is on Packagist, add a VCS repository to your `composer.json`:
> ```json
> "repositories": [{ "type": "vcs", "url": "https://github.com/MakFly/beacon-sdk-php.git" }]
> ```

## Symfony setup

**1. Register the bundle** — `config/bundles.php`:

```php
KevStudios\Beacon\Symfony\BeaconBundle::class => ['dev' => true, 'prod' => true],
```

> `dev`+`prod` only (not `test`) keeps your test suite free of outbound telemetry calls.

**2. Configure** — `config/packages/beacon.yaml`:

```yaml
when@dev: &beacon
    beacon:
        endpoint: '%env(BEACON_ENDPOINT)%'   # required — ingester base URL
        token:    '%env(BEACON_TOKEN)%'      # required — project token
        service_name: 'my-app'               # optional
when@prod: *beacon
```

Other options (with defaults): `service_version` (null), `stage` (`%kernel.environment%`),
`application_path` (`%kernel.project_dir%`), `collect_arguments` (true),
`traces_sample_rate` (1.0), `censor_keys` (`password, authorization, cookie, token, secret, api_key`).

Unhandled kernel exceptions are captured automatically; the buffer flushes on `kernel.terminate`.
The transport swallows every failure — telemetry never breaks the host app.

## Versioning & release

SemVer via **git tags** (Packagist-driven). See [`CLAUDE.md`](./CLAUDE.md). Tags are immutable.

## License

MIT. Part of the [Beacon](https://github.com/MakFly) telemetry suite.

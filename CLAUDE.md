# CLAUDE.md — makfly/beacon-sdk-php

Rules for working in this repo. Read before any change that ships.

## What this is

The PHP/Symfony 8 telemetry SDK (Symfony bundle). Distributed via **Packagist** as
`makfly/beacon-sdk-php`. No build step (PHP), no internal package deps.

⚠️ **Two distinct names — do not confuse them:**
- Composer package (vendor) name: `makfly/beacon-sdk-php` — this is what Packagist/`composer require` use.
- PHP namespace (PSR-4): `KevStudios\Beacon\` — the actual classes (`KevStudios\Beacon\Symfony\BeaconBundle`).

The composer name and the PHP namespace are **independent**. A vendor rename does **not**
touch the namespace, and vice-versa. **Never rename `KevStudios\Beacon\`** — it would break
every consumer's `config/bundles.php` and service wiring.

## Versioning (SemVer — strict)

There is **no `version` field** in `composer.json` — the version is the **git tag**, and
Packagist derives releases from tags. Tag format `vX.Y.Z`.

- **PATCH**: fix/internal, no API or config-schema change.
- **MINOR**: additive (new optional config key, new public method). Backward-compatible.
- **MAJOR**: breaking change (config schema, public API, bundle wiring, min PHP/Symfony).

## Release workflow

```bash
# 1. commit the change
git add -A && git commit -m "release: vX.Y.Z"
# 2. tag (SemVer) + push — Packagist auto-syncs via the GitHub webhook/app
git tag vX.Y.Z
git push origin main && git push origin vX.Y.Z
```

No `composer publish` — pushing the tag is the release (Packagist picks it up). If the
Packagist webhook isn't set, trigger an update manually from the package page on packagist.org.

## Hard rules

- **Tags are immutable.** Bad release → next PATCH, never rewrite a published tag.
- **Never rename the PSR-4 namespace `KevStudios\Beacon\`** (independent of the composer vendor name).
- Keep `require` ranges permissive (`symfony/* ^7 || ^8`) — this is a library, not an app.
- Run `composer validate` and the smoke tests (`php tests/smoke.php`) before tagging.
- Git **submodule** of a private telemetry monorepo → bump its pointer after release.
- `ig` for search, never `grep`/`rg`.

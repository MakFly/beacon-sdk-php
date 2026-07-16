# Changelog

## v0.6.0

Automatically decorates Symfony's concrete PSR-18 client and records privacy-safe
`http_client` spans under the active request trace. The span keeps method, host, port,
protocol and response status while excluding paths, query strings, headers and bodies.

## v0.5.2

The Symfony setup now writes `https://ingest.pulseview.app` as the default ingestion
endpoint. Applications can still override it for self-hosted and internal Docker routes;
an empty token keeps the SDK disabled.

## v0.5.1

Skips request and Doctrine span construction entirely when the configured transport is
disabled, preserving the SDK's zero-overhead no-op contract.

## v0.5.0

Adds automatic Doctrine DBAL 4 query spans under the active Symfony HTTP trace. SQL
operation names are normalized before capture so literal values never become telemetry
attributes or high-cardinality group keys.

## v0.4.0

Reliable transport with timeout, bounded retries, `429`/`Retry-After`, retained bounded
buffers, effective trace sampling, and recursive redaction across every signal.
Adds W3C `traceparent`, `tracestate` and `baggage` propagation helpers.

## v0.3.1

Privacy-safe patch release. Stack-frame arguments are now opt-in and disabled by default.
The transport identity is aligned with the package release.

## v0.1.2

Republish with clean git history. `v0.1.1`'s Packagist dist reference points to a commit
that was removed during a history rewrite — Packagist stable versions are **immutable**, so
that reference can never be updated. `v0.1.2` ships the same SDK from the current clean tag,
installable from Packagist with no VCS repository override. No API changes.

## v0.1.1

Security hardening (exception report builder, cURL transport) + CI security-audit workflow.

## v0.1.0

Initial release — Symfony 8 bundle, errors / traces / logs over the Beacon wire-protocol.

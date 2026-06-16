# Changelog

## v0.1.2

Republish with clean git history. `v0.1.1`'s Packagist dist reference points to a commit
that was removed during a history rewrite — Packagist stable versions are **immutable**, so
that reference can never be updated. `v0.1.2` ships the same SDK from the current clean tag,
installable from Packagist with no VCS repository override. No API changes.

## v0.1.1

Security hardening (exception report builder, cURL transport) + CI security-audit workflow.

## v0.1.0

Initial release — Symfony 8 bundle, errors / traces / logs over the Beacon wire-protocol.

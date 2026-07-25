# Changelog

## 2.0.0 - 2026-07-25

- Move module discovery, manifests, dependency validation, state, migrations,
  extensions, providers, compiled caching, and class-map/PSR-4 autoloading into
  the core package.
- Add a small dependency-injection container and modern request/response
  primitives.
- Add checksummed dependency-ordered MySQL/MariaDB migrations with an advisory
  deployment lock.
- Add atomic registry/state caches and remove production-time directory scans.
- Remove the need for generated root controller/model adapters.
- Fix database port handling, settings fetching, encoded path handling,
  template path traversal, logger locking, nested transactions, and the legacy
  pagination metadata TypeError.
- Require PHP 8.1 or newer.
- Deprecate the legacy terminating API helpers; they remain available for the
  2.x migration period.

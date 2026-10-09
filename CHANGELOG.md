# Changelog

## 2.2.0 - Unreleased

### Added
- `FloCMS\Core\DatabaseConnectionException` (extends `RuntimeException`), thrown by
  `App::db()` when the connection fails. It keeps the driver error code
  (`driverCode()`), a cause (`reason()`: server unavailable, access denied,
  unknown database) and the driver message (`detail()`, debug output only).
- `FloCMS\Core\DatabaseNotConfiguredException`, thrown when a model needs the
  database but `DB_NAME` / `DB_USERNAME` are empty.
- `App::dbStatus()` for setup screens (never throws), `App::hasDbConfig()` (now
  public) and `App::resetDb()`.

### Changed
- `Model` connects lazily on first use of `$this->db`, so creating a model no
  longer needs a database.
- A failed connection is remembered for the rest of the request instead of
  waiting for the connection timeout again.
- `ErrorHandler` renders `nodbserver.html` (HTTP 503) when the database server
  is unreachable and `dberror.html` (HTTP 500) for wrong credentials, an unknown
  database or a missing configuration, in debug mode too. In debug mode only,
  the page receives the driver message as `$detail`.
- PDO driver codes are now also read from connection error messages, so error
  pages show the right message for connection errors.

## 2.1.0 - Unreleased

### Added
- `FloCMS\Core\Auth`: role → permission checks for the logged-in user.
  `Auth::can()`, `Auth::authorize()` (throws 403), `Auth::canAssignRole()` and
  `Auth::canManageUser()`. Permissions come from `Config::get('permissions')`
  and support exact names, `prefix.*` and `*`.
- Per-action permissions on controllers: declare
  `protected array $actionPermissions = ['*' => 'users.manage', 'admin_login' => null];`
  and `App::run()` returns 403 when the current role lacks the permission.
- 403 responses render `templates/<template>/errors/403.html` when present
  (falls back to `500.html` with status 403).

### Upgrade notes
- Non-breaking. Without a `permissions` config, `Auth::can()` returns the
  existing `admin_access` session flag, and controllers without
  `$actionPermissions` are not checked, so existing sites behave as before.
- To opt in, add a map to `config/config.php`, e.g.
  `Config::set('permissions', [0 => [], 1 => ['content.*'], 2 => ['content.*', 'users.manage', 'users.assign_role'], 3 => ['*']]);`
  then declare `$actionPermissions` on your admin controllers.
- Once a map is configured, roles missing from it are denied every permission.
- Roles are still read from the session set at login; changes take effect on
  the next login.

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

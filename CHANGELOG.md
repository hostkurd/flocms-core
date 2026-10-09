# Changelog

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

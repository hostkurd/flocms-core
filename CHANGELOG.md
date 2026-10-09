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
- `Auth::refresh()`: with `Config::set('auth.user_loader', fn (int $id) => ...)`,
  every admin request reloads the user's role and status. Suspended or deleted
  users are logged out at once; role changes apply on the next request and
  `admin_access` follows `admin_access_roles`. Options: `auth.active_status`
  (default 1) and `auth.refresh_interval` (seconds, default 0 = every request).
- `Auth::endSession()` clears the session and issues a new session id.
- Query builder (`Database`):
  - grouped conditions: `where(Closure)`, `orWhere(Closure)`, `having(Closure)`,
    `orHaving(Closure)`, producing parenthesised (nestable) groups;
  - `whereBetween()`, `orWhereBetween()`, `whereNotBetween()`, `orWhereNotBetween()`
    taking `[min, max]`;
  - `whereRaw()`, `orWhereRaw()`, `havingRaw()` with required bindings (one per
    `?`; named placeholders are rejected); `selectRaw()` and the new
    `orderByRaw()` accept optional bindings;
  - `paginate($page, $perPage)` returning `['data' => rows, 'pagination' => ...]`
    in the `Model::pagingArray()` format (grouped queries count groups);
  - `toSql()` returns the SELECT and its bindings without running it.
- `FloCMS\Core\Pagination::meta()`, now used by `Model::pagingArray()`.
- Validator `numeric` rule.
- `Lang::get($key, $default = '', array $replace = [])` replaces `:name`
  placeholders (`:Name` / `:NAME` give capitalised / upper-case values);
  `Lang::replace()`.
- Global `e()` helper for HTML escaping (defined only if no `e()` exists).
- `TemplateEngine::compiledPath()`, `cacheDirectory()` and `renderFile()`.
- `@csrf` template directive, rendering `Csrf::field()`.

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
- `Database::table()` returns a new builder per query, so a query started
  while another is being built no longer resets it. Calls made directly on the
  shared instance after a separate `table()` statement still apply to that
  query, as before. The transaction depth is shared by all builders of a
  connection.
- Validator `integer` accepts integer strings from forms and JSON (`"5"`).
- Validator `min` / `max` compare numbers when the field also has `integer` or
  `numeric` (`integer|min:1000`); otherwise strings are compared by length in
  characters (multibyte-safe) and arrays by item count. Messages say which.
- Templates are compiled once to PHP files and `include`d instead of `eval()`ed
  on every request, so OPcache can cache them (`View::render()` and
  `render_partial()`). Compiled files live in `views/cache/` (or Config
  `view.cache_path`), are named by template path + mtime/size + compiler
  version, are written atomically, and older versions are removed. If the
  directory is not writable, rendering falls back to `eval()`. Disable with
  `Config::set('view.cache', false)`.
- A template that throws no longer leaves its output buffer open.
- `TemplateEngine::CreateView()` is deprecated and now returns the same
  compiled file.
- `Functions::e()` is static (calling it on an instance still works).
- `__()` passes placeholders to `Lang::get()` instead of relying on a caught
  `TypeError`; missing keys still return the default or the key.
- `ValidationException` declares `?Exception $previous` (PHP 8.4 deprecation).
- On SQLite, integer bindings are bound as integers (comparisons such as
  `COUNT(*) > ?` failed before). Other drivers are unchanged.

## 2.1.0 - 2026-10-09

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
  the next login (2.2 adds `Auth::refresh()` to apply them immediately).

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

# Flo Framework Core

`hostkurd/flocms-core` is the application foundation for Flo Framework.
Version 2 keeps shared HTTP primitives in core and moves all module
discovery, validation, lifecycle, state, caching, and migrations into this
package.

API applications should additionally install `hostkurd/flocms-api`. The API
package depends on core; core never depends on the API package.

## Package boundary

Core owns:

- the application runtime and existing MVC compatibility layer;
- `Request`, `Response`, configuration, database, logging, and security helpers;
- a small reflection-based dependency-injection container;
- module manifests, dependency validation, compiled discovery, autoloading,
  providers, enable/disable state, audit records, and migrations.

For compatibility with Flo 1.x applications, short names declared in
`controllers` and `models` are class-mapped directly from a module's `Http/`
and `Models/` directories. Generated adapter files in the application's root
`controllers/` and `models/` directories are no longer required. New modules
should prefer their own PSR-4 namespace through the `autoload` manifest key.

An application owns only:

- `modules/` for project modules;
- `api/` for application API routes/controllers when the optional API package is
  installed;
- configuration, public entry points, templates, and storage.

No module directory is scanned during a production HTTP request. Run a sync
command during deployment to compile the registry into
`storage/cache/modules.php`.

## Module manifest

Each project module is placed at `modules/<module-id>/module.php`:

```php
<?php

return [
    'id' => 'news',
    'name' => 'News',
    'description' => 'Company news and categories.',
    'version' => '1.0.0',
    'schema_version' => 1,
    'kind' => 'optional',
    'default_enabled' => true,
    'priority' => 100,
    'dependencies' => [],
    'autoload' => [
        'App\\Modules\\News\\' => 'src/',
    ],
    'providers' => [
        App\Modules\News\NewsServiceProvider::class,
    ],
    'routes' => [
        'api' => 'routes/api.php',
    ],
    'migrations' => [
        'database/migrations/001_create_news.php',
    ],
];
```

Paths in a manifest are resolved inside that module. Traversal outside the
module is rejected.

## Bootstrap

```php
use FloCMS\Core\Modules\ModulePaths;
use FloCMS\Core\Modules\ModuleSystem;

$paths = ModulePaths::fromRoot(ROOT);

ModuleSystem::configure(
    paths: $paths,
    database: \FloCMS\Core\App::db(),
    allowRuntimeDiscovery: false,
);

ModuleSystem::autoloader()->register();
ModuleSystem::bootstrapper()->boot();
```

During deployment:

```php
$result = ModuleSystem::synchronizer()->sync();
```

The migration runner uses a database advisory lock on MySQL/MariaDB so two
deployments cannot migrate modules concurrently. Applied migrations are
checksummed and must never be edited; create a new migration instead.

## Database errors and lazy connections (2.2+)

Models connect on first use of `$this->db`, so pages that never query the
database work without one. When the connection fails, `App::db()` throws
`DatabaseConnectionException`; the error handler renders
`templates/<template>/errors/nodbserver.html` (server unreachable, HTTP 503) or
`dberror.html` (access denied, unknown database, not configured). Templates
receive `$message`, `$errorCode`, `$status` and, in debug mode only, `$detail`
(the driver message). `App::dbStatus()` reports the state without throwing:

```php
$status = App::dbStatus();
// ['configured' => true, 'connected' => false, 'reason' => 'unknown_database',
//  'code' => 1049, 'message' => 'Database not found.', 'detail' => '...']
```

## Query builder (2.2+)

`table()` starts a new, independent query:

```php
$db = App::db();

// status = ? AND (title LIKE ? OR city LIKE ?)
$page = $db->table('listings')
    ->where('status', '=', 'active')
    ->where(fn ($q) => $q->where('title', 'LIKE', "%{$term}%")
                         ->orWhere('city', 'LIKE', "%{$term}%"))
    ->whereBetween('price', [$min, $max])
    ->whereRaw('MATCH(title, body) AGAINST(? IN BOOLEAN MODE)', [$words])
    ->orderBy('created_at', 'DESC')
    ->paginate($pageNumber, 20);

$page['data'];        // rows (objects)
$page['pagination'];  // same keys as Model::pagingArray()

// Distance search (MySQL 5.7+/MariaDB 10.5+): bindings are required
$near = $db->table('listings')
    ->select('id, title')
    ->selectRaw('ST_Distance_Sphere(location, POINT(?, ?)) AS meters', [$lng, $lat])
    ->whereRaw('ST_Distance_Sphere(location, POINT(?, ?)) <= ?', [$lng, $lat, 5000])
    ->orderByRaw('meters ASC')
    ->get();
```

Raw SQL (`whereRaw`, `orWhereRaw`, `havingRaw`, `selectRaw`, `orderByRaw`)
must use `?` placeholders with one binding each; never concatenate input into
it. A literal `?` inside a quoted string counts as a placeholder, so bind such
values too. `toSql()` returns the SQL and bindings without running the query.

## Compatibility

The legacy `FloCMS\Core\Api` and `FloCMS\Core\ApiController` classes remain in
core for the 2.x transition. New APIs should use `hostkurd/flocms-api`; the
legacy classes are not the basis of the new router.

## Upgrading

### 2.1 → 2.2

2.2 is non-breaking: sites with a working database behave as before.

- **Database errors:** `App::db()` throws `DatabaseConnectionException` instead
  of a plain `RuntimeException`. It extends `RuntimeException`, so existing
  `catch` blocks still work. A model without database configuration throws
  `DatabaseNotConfiguredException` (extends `RuntimeException`) on first query
  instead of `Exception` in its constructor.
- **Session freshness (opt-in):** store the user id in the session at login
  (`Session::set('user_id', $user['id'])`) and configure a loader:
  `Config::set('auth.user_loader', fn (int $id) => (new UsersModel())->getByID($id));`.
  Admin requests then reload role/status from the database. Sessions created
  before this change have no `user_id` and are logged out once. Without a
  loader nothing changes. The logout message uses the `auth.session_ended`
  language key when present.
- **Query builder:** `table()` now returns a new builder. Chained code is
  unaffected; code that calls `$db->table('x');` and then `$db->where(...)`
  as separate statements also keeps working, because calls on the shared
  instance go to the query its last `table()` started. Conditions added on the
  shared instance *before* calling `table()` are no longer carried into the
  query. `where()` now also accepts a `Closure`; a subclass overriding
  `where()`, `orWhere()`, `having()` or `orHaving()` must widen its signature.
- **Error pages:** database connection errors now use `nodbserver.html` and
  `dberror.html`. Make sure your template has both (or they fall back to
  `500.html`), and print `$detail` if you want the driver message in debug mode.

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

Database tests run on in-memory SQLite. Tests that need a real MySQL/MariaDB
server are skipped unless `FLO_TEST_MYSQL_HOST` is set:

```bash
FLO_TEST_MYSQL_HOST=127.0.0.1 FLO_TEST_MYSQL_USER=flo FLO_TEST_MYSQL_PASS=flo \
FLO_TEST_MYSQL_NAME=flocms_test vendor/bin/phpunit
```

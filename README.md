# Flo Framework Core

`hostkurd/flocms-core` is the application foundation for Flo Framework.
Version 2 keeps shared HTTP primitives in core and moves all module
discovery, validation, lifecycle, state, caching, and migrations into this
package.

API applications should additionally install `hostkurd/flocms-api`. The API
package depends on core; core never depends on the API package.

## Explicit controller responses

Controllers may return a `FloCMS\Core\Http\Response` instead of a view path:

```php
public function results(): \FloCMS\Core\Http\Response
{
    return \FloCMS\Core\Http\Response::html('<p>Updated results</p>')
        ->header('Cache-Control', 'no-store');
}
```

Core sends the explicit response without adding a layout. The normal CSRF,
authentication, and action-permission checks still run. Existing controllers
returning view paths keep their behavior. HEAD responses send status and headers
without a body. This supports HTML fragments and JSON in optional packages without
making Core depend on those packages. Available from 2.3.2.

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

## Controller actions

A URL like `/{lang}/{route}/{controller}/{action}/{params}` calls the action
method on the controller, with the route's prefix (`admin_` under `/admin`).
Only public, non-static methods declared on your controller (or on your own
base controller) are actions. Methods inherited from `FloCMS\Core\Controller`,
protected, private and static methods, the constructor and names starting with
`__` answer 404, so keep helpers `protected` or `private`.

## Controllers from packages (2.3+)

The controller segment maps to `FloCMS\Controllers\<Name>Controller` in your
application. When that class doesn't exist, packages can supply one with a
resolver. It receives the controller segment and the route (`'default'`,
`'admin'`, ...) and returns a controller class, or `null` to pass:

```php
use FloCMS\Core\App;

App::resolveControllersUsing(static function (string $controller, string $route): ?string {
    return $route === 'admin' && in_array($controller, ['properties', 'agents'], true)
        ? \Vendor\Admin\ResourceController::class
        : null;
});
```

`/admin/properties/edit/5` then calls `ResourceController::admin_edit()` with
`['5']` as its parameters; `App::getRouter()->getController()` is
`'properties'`.

- Application controllers always win: resolvers are only asked when your
  `controllers/` has no matching class.
- Resolvers are asked in registration order; the first class returned is used.
- A returned value that isn't an instantiable `FloCMS\Core\Controller`
  subclass answers 404.
- Resolvers run after the admin login check, and the usual rules apply to the
  resolved controller: only its declared public actions are callable, and
  `$actionPermissions` is enforced.
- Register resolvers before `App::run()`, for example in `config/config.php`
  or a module's provider. `App::clearControllerResolvers()` removes them all.

## Pages without a controller (2.6+)

A package can answer public URLs that no controller answers, for example
pages it keeps in the database:

```php
use FloCMS\Core\App;
use FloCMS\Core\Http\{Request, Response};

App::fallbackUsing(static function (string $path, Request $request): ?Response {
    $page = Pages::find($path);            // "about", "services/web-design", '' for the home page
    return $page === null ? null : Response::html($page->render());
});
```

- Asked only on the default route (never for `admin`, `login` or `api` URLs),
  after CSRF and the maintenance page, when the URL would otherwise answer
  404: no controller of that name (the application's or a resolver's), no such
  action, or a segment that cannot name a controller or action (such as
  `web-design` in second place).
- Application controllers and resolvers always win.
- `$path` is the path after the language and route, decoded, in the case it
  was requested. `App::getRouter()->getPath()` gives the same.
- Fallbacks are asked in order; return a `Response`, or `null` to pass. When
  none answers the request is a 404. Returning anything else throws.
- The response is sent as it is: render the site's layout yourself.
- `App::clearFallbacks()` removes them all.

## Admin pages for guests (2.5+)

Under the `admin` route, a visitor who isn't signed in only reaches
`users/admin_login`; every other admin action redirects to the login page.
A package or the site can open a few more, for example a "forgot password"
page:

```php
use FloCMS\Core\App;

App::allowGuestAdminActions('password', ['admin_forgot', 'admin_reset'], \Vendor\Admin\PasswordController::class);
```

`/admin/password/forgot` and `/admin/password/reset/<token>` now run for
guests; `/admin/password/anything-else` still redirects.

- Pass the class that serves the actions (third argument) so guests reach
  only that class. Without it, whatever controller answers the URL is open
  to guests, including a site's own controller of the same name; packages
  should always pass it.
- Names are exact (controller segment and method, compared in lower case);
  there are no wildcards. Invalid names throw `InvalidArgumentException`.
- Only the login check is skipped. CSRF on POST, controller resolvers,
  the declared-public-action check and `$actionPermissions` still apply, so
  leave these actions out of `$actionPermissions` (or map them to `null`).
- Signed-in users reach them too; send them elsewhere in the action if that
  makes more sense.
- `App::isGuestAdminAction($controller, $method)` tells whether an action is
  open; `App::clearGuestAdminActions()` removes them all.

## Session checks (2.5+)

`Auth::refresh()` reloads the signed-in user through `auth.user_loader` on
admin requests and ends the session of a deleted or suspended user. A package
can add its own checks on the loaded user; returning `false` ends the session
the same way:

```php
use FloCMS\Core\Auth;
use FloCMS\Core\Session;

// End other sessions after a password change
Auth::checkSessionsUsing(static function (array $user): bool {
    $stamp = substr(hash('sha256', (string) ($user['password'] ?? '')), 0, 16);
    if (Session::get('password_stamp') === null) {
        Session::set('password_stamp', $stamp);
    }
    return Session::get('password_stamp') === $stamp;
});
```

- Checks run after the status check, in the order they were added, only when
  `auth.user_loader` is set; with `auth.refresh_interval` above 0, only when
  the user is reloaded.
- A check that throws is logged and ends the session.
- `Auth::clearSessionChecks()` removes them all.

## Roles from packages (2.4+)

Roles and permissions normally come from `config/config.php` (`roles`,
`permissions`, `admin_access_roles`). A package or the site can supply them at
runtime instead, for example from a database table that an admin page edits:

```php
use FloCMS\Core\Auth;

Auth::loadRolesUsing(static function (): ?array {
    return [
        'roles'              => [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin', 4 => 'Author'],
        'permissions'        => [1 => ['content.*'], 2 => ['content.*', 'users.manage'], 3 => ['*'], 4 => ['content.articles.*']],
        'admin_access_roles' => ['1', '2', '3', '4'],
        'levels'             => [4 => 1],
    ];
});
```

- The loader runs once per request, the first time a check needs it
  (`App::run()` forgets the previous result, so long-running workers ask
  again on each request).
  `Auth::forgetLoadedRoles()` makes the next check ask again (after saving
  roles in the same request); `Auth::clearRolesLoader()` removes it.
- Each key the loader returns replaces that `Config` value; keys it leaves
  out keep the config values. Returning `null` means "use the config".
- A loader that throws, or returns something other than an array or `null`,
  is logged once and ignored for the request: the config roles apply, so a
  broken store never locks everybody out.
- Permission checks made inside the loader see the config roles.
- `Auth::roles()` returns whatever names the loader gives (flocms-admin gives
  them in the current panel language), not lang keys.

Everything reads through the loader: `Auth::can()`, `Auth::refresh()` (which
sets `admin_access` on each admin request), `Auth::canAssignRole()` and
`Auth::canManageUser()`, plus these helpers:

| Method | Returns |
| --- | --- |
| `Auth::roles()` | role number ⇒ name |
| `Auth::permissionMap()` | role number ⇒ permission patterns, or `null` (legacy mode) |
| `Auth::adminAccessRoles()` | roles that may open `/admin`, as strings, or `null` |
| `Auth::hasAdminAccess($role)` | `true`/`false`, or `null` when nothing is configured |
| `Auth::level($role)` | the role's rank |

**Levels.** `canAssignRole()` (give a role up to your own) and
`canManageUser()` (manage strictly lower roles) compare levels. A role's level
is its `levels` entry, or its number when it has none, so sites without
`levels` behave exactly as before. Levels let a new role rank between existing
ones without renumbering `users.role`: role 4 above with level 1 ranks with
Editors.

## Compatibility

The legacy `FloCMS\Core\Api` and `FloCMS\Core\ApiController` classes remain in
core for the 2.x transition. New APIs should use `hostkurd/flocms-api`; the
legacy classes are not the basis of the new router.

## Upgrading

### 2.5 → 2.6

No changes needed. `App::fallbackUsing()` and `Router::getPath()` are new;
nothing changes unless a package or the site calls them. A URL that is not a
valid controller or action name still answers 404 when no fallback is set.

### 2.4 → 2.5

No changes needed. `App::allowGuestAdminActions()` and
`Auth::checkSessionsUsing()` are new; nothing changes unless a package or the
site calls them.

### 2.3 → 2.4

2.4 is non-breaking. Nothing changes until something calls
`Auth::loadRolesUsing()`. Applications that compare role numbers themselves for
ranking can switch to `Auth::level()` so they agree with core once levels are
used. Login code that keeps its own list of admin roles can ask
`Auth::hasAdminAccess($role)` first and fall back to its list when that returns
`null`.

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
- **Validator:** `integer` now accepts `"5"`. `min`/`max` on a field with
  `integer` or `numeric` compare the value; without them they compare the
  string length, as before but counting characters instead of bytes. Add
  `integer` or `numeric` to fields like prices (`integer|min:1000`) that relied
  on the old numeric fallback for empty strings.
- **Translations:** `__('key', ['name' => $name])` and
  `Lang::get('key', '', ['name' => $name])` now replace `:name`.
- **Template cache:** make sure `views/cache/` (or your `view.cache_path`)
  is writable by the web server and ignored by git. Files left there by
  `TemplateEngine::CreateView()` can be deleted. Templates are recompiled
  automatically when they change; delete the directory's contents to force it.
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

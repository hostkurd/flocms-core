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

## Compatibility

The legacy `FloCMS\Core\Api` and `FloCMS\Core\ApiController` classes remain in
core for the 2.x transition. New APIs should use `hostkurd/flocms-api`; the
legacy classes are not the basis of the new router.

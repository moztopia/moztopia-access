# Moztopia Access

Explicit global and team-scoped roles and permissions for Laravel. Composer package `moztopia/access`, namespace `Moztopia\Access`. Built independently with native Laravel facilities; no Spatie dependency or implementation.

```php
$user->assignRole('admin');
$user->hasPermission('documents.edit', team: $team);
```

This is a Composer library, with no UI, authentication, automatic routes, administrator bypass, or tenancy system. The `admin` name has no special powers.

## Installation

Targets Laravel 12/13 on PHP `~8.4.27 || ~8.5.12`. See [tested combinations and database requirements](https://github.com/moztopia/moztopia-access/wiki/Testing-and-Compatibility).

Install the latest 0.9 release from Packagist:

```sh
composer require moztopia/access:^0.9
php artisan vendor:publish --tag=moztopia-access-config
```

The provider is discovered automatically. Set `moztopia-access.user_model` to the host application's Eloquent authenticatable class **before migrating**. Its primary key must be a canonical UUID string and must exist before the package migration runs. All Access tables and the user table must use the same default database connection. SQLite (foreign keys enabled) and MariaDB 11.8/InnoDB are supported; MySQL, PostgreSQL, SQL Server and binary UUID storage are not declared supported.

```sh
php artisan vendor:publish --tag=moztopia-access-migrations
php artisan migrate
```

Migrations are published, never loaded automatically. Review the published migration and backup data before production changes. Do not publish a second copy after installation.

## User model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Moztopia\Access\Traits\HasAccess;

class User extends Authenticatable
{
    use HasAccess;
    use HasUuids; // Omit if the host already generates canonical UUIDs.
}
```

Keep the host's existing traits and properties. Access adds no `can`, `cannot`, Gate hooks, policies or authentication behavior. Check for a conflicting `teams()` relationship before adopting `HasAccess`.

## Quick start

These are trusted server-side operations. Authorize the actor before exposing them to a request, command or job.

```php
use Moztopia\Access\Services\AccessService;

$access = app(AccessService::class);
$access->definePermission('documents.edit');
$access->defineRole('editor', ['documents.edit']);
$access->defineRole('admin'); // Empty role; no bypass.

$user->assignRole('editor');
$user->hasRole('editor'); // true
$user->hasPermission('documents.edit'); // true
$user->revokeRole('editor');

$team = $access->createTeam('editorial', displayName: 'Editorial');
$access->addMember($team, $user);
$user->assignRole('editor', team: $team);
$user->hasPermission('documents.edit', team: $team); // true
$user->hasPermission('documents.edit'); // false

$user->assignPermission('documents.edit', team: $team);
$user->revokeRole('editor', team: $team);
$user->hasPermission('documents.edit', team: $team); // Still true via direct grant.
$user->revokePermission('documents.edit', team: $team);
$user->hasTeam($team); // true
$user->teams()->paginate(25);
```

## Assignment and revocation commands

Use `assignRole()`, `assignPermission()` and `assignTeam()`, paired with `revokeRole()`, `revokePermission()` and `revokeTeam()`. Permission checks use `hasPermission()`; role and membership checks use `hasRole()` and `hasTeam()`. There are no alternate mutation spellings or compatibility bridges. See the [naming decision](https://github.com/moztopia/moztopia-access/wiki/API-Naming).

Teams accept an existing model or a unique application-defined name:

```php
$user->assignTeam('editorial'); // Membership only; the team must already exist.
$user->assignRole('editor', team: 'editorial');
$user->hasPermission('documents.edit', team: 'editorial');
$user->revokeTeam('editorial'); // Removes membership and its scoped grants; keeps the team.
```

Team lookup names stay stable when their separate display labels change. No team name is built in.

## Scope and security

Definitions are application-wide. Omitting `team` affects/checks only application-wide assignments. Supplying `team` requires active membership and considers global grants plus that exact team's grants. Another team's grants never contribute. Membership alone grants nothing and never filters application data.

Removing membership deletes that user's team grants; rejoining does not restore them. Grants are idempotent. Revoking an absent assignment is safe. Unknown definitions throw during mutations and return false during checks. Direct grants and role-derived access remain independent.

`$user` identifies the **target**, never the actor. Public PHP mutations cannot protect against arbitrary trusted application code calling them. Hosts must authorize management operations. Use native Gates and policies for ordinary authorization, passing the team explicitly; see the [authorized controller/policy examples](https://github.com/moztopia/moztopia-access/wiki/Authorized-Integration).

Every check reads the database. There is no persistent or process-local permission cache. Transactions, uniqueness constraints, membership foreign keys and row locks protect package mutations; [the concurrency boundary](https://github.com/moztopia/moztopia-access/wiki/Architecture-and-Schema) explains in-flight checks, database isolation and host responsibilities.

## Documentation and development

The [full wiki](https://github.com/moztopia/moztopia-access/wiki) includes [Agents Start Here](https://github.com/moztopia/moztopia-access/wiki/Agents-Start-Here), the [complete PHP API](https://github.com/moztopia/moztopia-access/wiki/Public-PHP-API), schema, integration, testing, operations guidance and a [capability review of Spatie, Bouncer and Laratrust](https://github.com/moztopia/moztopia-access/wiki/Capability-Review). See [CONTRIBUTING.md](CONTRIBUTING.md) for local checks and project controls.

```sh
composer install
composer validate
composer test
composer lint
```

Released under the [MIT License](LICENSE). First release: **0.9.0 — Padlock**. [Sponsor this work](https://buymeacoffee.com/mozrin).

Copyright © 2026 Moztopia.

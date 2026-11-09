# Contributing

Track changes in a bounded [repository issue](https://github.com/moztopia/moztopia-access/issues) in the [Moztopia Access Project](https://github.com/orgs/moztopia/projects/49). Read the wiki's [Agents Start Here](https://github.com/moztopia/moztopia-access/wiki/Agents-Start-Here) before implementation.

Use `wip/{major}/{issue-number}-{slug}` branches; the current source major is 0. Open coherent PRs to `develop`. Preserve focused tests and necessary docs with each change. Follow `Backlog → Ready → In progress → Testing → Done`; Done requires accepted integration and satisfied acceptance, not a branch push.

PHP 8.4.27 or 8.5.12 with Composer 2 and PDO SQLite can run the default tests. Dependencies are development-only except declared Illuminate components. Do not install dependencies on a workstation when its policy requires isolated containers. A Laravel application scaffold or server is unnecessary.

Run `composer install`, `composer validate`, `composer test` and `composer lint`. Apply formatting with `vendor/bin/pint`. Preserve the blank line after PHPDoc blocks. The expected missing-license warning is not permission to select a license; strict Composer validation remains blocked until an authorized selection.

Use only disposable test databases: the suite drops and recreates all `moztopia_access_*` tables and `access_test_users`. Never point the suite at a consuming application's database. The default database is in-memory SQLite; concurrency tests create temporary SQLite files. They execute independent PHP processes through `proc_open`; do not use Pest parallel execution against one shared database.

For a dedicated MariaDB 11.8/InnoDB database, provision a synthetic `access_test` user/password/database, enable `pdo_mysql`, then run:

```sh
ACCESS_DB_DRIVER=mysql ACCESS_DB_HOST=database ACCESS_DB_DATABASE=access_test composer test
```

The literal fixture credentials are test-only and must never be used for deployment. The test database must accept connections from child test processes. No host ports are needed with a shared Docker network. Test PHP 8.4/8.5 against Laravel 12/Testbench 10 and Laravel 13/Testbench 11; Composer constraints alone do not establish compatibility. See the wiki's verification record.

Use the ignored local `wiki/` Git clone for wiki changes. Discover its actual default branch, review diffs, fetch/reconcile and push normally. Record the wiki commit on the parent issue. Do not edit wiki pages in the browser beyond first-page bootstrap.

Keep secrets, private infrastructure and proprietary application details out of commits. Do not add releases, tags, package publication or deployment automation without following the owner's controls. The owner chooses the license. See [security reporting](.github/SECURITY.md) for vulnerabilities.

Use `assignRole()`, `assignPermission()` and `assignTeam()` as the preferred commands in new examples. Keep documented aliases semantically identical and supported. Follow the [local API naming decision](https://github.com/moztopia/moztopia-access/wiki/API-Naming); do not promote illustrative team names into package conventions.

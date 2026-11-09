<?php

declare(strict_types=1);

namespace Moztopia\Access\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moztopia\Access\AccessServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boot a synthetic UUID host on the selected disposable test database.
 */

abstract class TestCase extends Orchestra
{
    /**
     * Register the discoverable provider in the test host.
     */

    protected function getPackageProviders($app): array
    {
        return [AccessServiceProvider::class];
    }

    /**
     * Configure only isolated fixtures, never a real application database.
     */

    protected function defineEnvironment($app): void
    {
        $driver = getenv('ACCESS_DB_DRIVER') ?: 'sqlite';
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => $driver,
            'database' => getenv('ACCESS_DB_DATABASE') ?: ':memory:',
            'host' => getenv('ACCESS_DB_HOST') ?: '127.0.0.1',
            'port' => 3306,
            'username' => 'access_test',
            'password' => 'access_test',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'strict' => true,
        ]);
        $app['config']->set('moztopia-access.user_model', AccessTestUser::class);
    }

    /**
     * Recreate only the selected disposable database's synthetic tables.
     */

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ACCESS_WORKER') === '1') {
            return;
        }
        $migration = require __DIR__.'/../database/migrations/2026_11_09_000001_create_moztopia_access_tables.php';
        $migration->down();
        Schema::dropIfExists('access_test_users');
        Schema::create('access_test_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
        $migration->up();
    }
}

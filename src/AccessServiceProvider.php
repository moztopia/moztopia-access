<?php

declare(strict_types=1);

namespace Moztopia\Access;

use Illuminate\Support\ServiceProvider;

/**
 * Register publishable Access resources without changing host authorization.
 */

final class AccessServiceProvider extends ServiceProvider
{
    /**
     * Merge the host model configuration.
     */

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/moztopia-access.php', 'moztopia-access');
    }

    /**
     * Expose configuration and migrations for explicit installation.
     */

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/moztopia-access.php' => config_path('moztopia-access.php')], 'moztopia-access-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'moztopia-access-migrations');
        }
    }
}

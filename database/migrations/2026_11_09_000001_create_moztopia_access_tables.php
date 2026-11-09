<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Install UUID definitions, memberships and constrained assignments.
     */

    public function up(): void
    {
        $class = config('moztopia-access.user_model');
        /**
         * Resolve the host user table without modifying it.
         *
         * @var Model $user
         */

        if (!is_string($class) || !is_subclass_of($class, Model::class) || !is_subclass_of($class, Authenticatable::class)) {
            throw new RuntimeException('Configure moztopia-access.user_model as an Eloquent authenticatable before migrating.');
        }
        $user = new $class();
        if ($user->getConnection()->getName() !== DB::connection()->getName()) {
            throw new RuntimeException('Access and its host user must share the default database connection.');
        }
        if (!in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Access currently supports SQLite and MariaDB only.');
        }
        $users = $user->getTable();
        $key = $user->getKeyName();

        foreach (['permissions', 'roles', 'teams'] as $entity) {
            Schema::create('moztopia_access_'.$entity, function (Blueprint $table) use ($entity): void {
                $table->uuid('id')->primary();
                $table->string('name', 128)->unique();
                if ($entity === 'teams') {
                    $table->string('display_name', 128);
                }
                $table->timestamps();
            });
        }

        Schema::create('moztopia_access_role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('moztopia_access_roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('moztopia_access_permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id'], 'ma_role_permissions_pk');
        });

        Schema::create('moztopia_access_memberships', function (Blueprint $table) use ($users, $key): void {
            $table->foreignUuid('team_id')->constrained('moztopia_access_teams')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references($key)->on($users)->cascadeOnDelete();
            $table->primary(['team_id', 'user_id'], 'ma_memberships_pk');
            $table->index('user_id', 'ma_memberships_user');
        });

        foreach (['role', 'permission'] as $kind) {
            Schema::create('moztopia_access_'.$kind.'_assignments', function (Blueprint $table) use ($kind, $users, $key): void {
                $table->uuid('user_id');
                $table->foreign('user_id')->references($key)->on($users)->cascadeOnDelete();
                $table->foreignUuid($kind.'_id')->constrained('moztopia_access_'.$kind.'s')->cascadeOnDelete();
                $table->uuid('team_id')->nullable();
                $table->foreign(['team_id', 'user_id'], 'ma_'.$kind.'_membership_fk')
                    ->references(['team_id', 'user_id'])->on('moztopia_access_memberships')->cascadeOnDelete();
                $table->uuid('scope_key')->storedAs("coalesce(rtrim(team_id), '00000000-0000-0000-0000-000000000000')");
                $table->unique(['user_id', 'scope_key', $kind.'_id'], 'ma_'.$kind.'_assignment_unique');
                $table->index(['team_id', 'user_id'], 'ma_'.$kind.'_membership');
            });
        }
    }

    /**
     * Remove package tables in foreign-key dependency order.
     */

    public function down(): void
    {
        foreach (['permission_assignments', 'role_assignments', 'memberships', 'role_permissions', 'teams', 'roles', 'permissions'] as $table) {
            Schema::dropIfExists('moztopia_access_'.$table);
        }
    }
};

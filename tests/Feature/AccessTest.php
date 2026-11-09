<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Moztopia\Access\AccessServiceProvider;
use Moztopia\Access\Models\AccessPermission;
use Moztopia\Access\Models\AccessRole;
use Moztopia\Access\Models\AccessTeam;
use Moztopia\Access\Services\AccessService;
use Moztopia\Access\Tests\AccessTestUser;

beforeEach(function (): void {
    $this->access = app(AccessService::class);
    $this->user = AccessTestUser::create();
    $this->other = AccessTestUser::create();
    $this->a = $this->access->createTeam('editorial', displayName: 'Editorial');
    $this->b = $this->access->createTeam('sales', displayName: 'Sales');
    $this->access->definePermission('documents.edit');
    $this->access->definePermission('documents.read');
    $this->access->defineRole('editor', ['documents.edit']);
    $this->access->defineRole('admin');
});

it('checks global roles and direct and inherited permissions independently', function (): void {
    expect($this->user->hasRole('editor'))->toBeFalse();
    $this->user->grantRole('editor');
    expect($this->user->hasRole('editor'))->toBeTrue()
        ->and($this->user->hasPermission('documents.edit'))->toBeTrue()
        ->and($this->other->hasPermission('documents.edit'))->toBeFalse();
    $this->user->revokePermission('documents.edit');
    expect($this->user->hasPermission('documents.edit'))->toBeTrue();
    $this->user->grantPermission('documents.edit');
    $this->user->revokeRole('editor');
    expect($this->user->hasPermission('documents.edit'))->toBeTrue()
        ->and($this->user->hasRole('editor'))->toBeFalse();
    $this->user->revokePermission('documents.edit');
    expect($this->user->hasPermission('documents.edit'))->toBeFalse();
});

it('does not infer roles from equivalent direct permissions or special admin names', function (): void {
    $this->user->grantPermission('documents.edit');
    $this->user->grantRole('admin');
    expect($this->user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasPermission('documents.read'))->toBeFalse()
        ->and($this->user->hasPermission('undefined'))->toBeFalse();
});

it('applies exact team scope and requires membership even for global access', function (): void {
    $this->user->grantRole('admin');
    $this->user->grantPermission('documents.read');
    expect($this->user->hasRole('admin', team: $this->a))->toBeFalse()
        ->and($this->user->hasPermission('documents.read', team: $this->a))->toBeFalse();
    $this->access->addMember($this->a, $this->user);
    $this->access->addMember($this->b, $this->user);
    $this->user->grantRole('editor', team: $this->a);
    expect($this->user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasRole('editor', team: $this->a))->toBeTrue()
        ->and($this->user->hasRole('editor', team: $this->b))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit'))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit', team: $this->a))->toBeTrue()
        ->and($this->user->hasPermission('documents.edit', team: $this->b))->toBeFalse()
        ->and($this->user->hasRole('admin', team: $this->a))->toBeTrue()
        ->and($this->user->hasPermission('documents.read', team: $this->a))->toBeTrue();
});

it('inherits global role permissions only for active members', function (): void {
    $this->user->grantRole('editor');
    expect($this->user->hasPermission('documents.edit', team: $this->a))->toBeFalse();
    $this->access->addMember($this->a, $this->user);
    expect($this->user->hasPermission('documents.edit', team: $this->a))->toBeTrue();
});

it('isolates direct team grants and scope-specific revocations', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->access->addMember($this->b, $this->user);
    $this->user->grantPermission('documents.edit', team: $this->a);
    $this->user->revokePermission('documents.edit');
    expect($this->user->hasPermission('documents.edit', team: $this->a))->toBeTrue()
        ->and($this->user->hasPermission('documents.edit'))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit', team: $this->b))->toBeFalse();
    $this->user->grantPermission('documents.edit');
    $this->user->revokePermission('documents.edit', team: $this->a);
    expect($this->user->hasPermission('documents.edit', team: $this->a))->toBeTrue();
});

it('requires active membership on team grants', function (string $method): void {
    $name = $method === 'grantRole' ? 'editor' : 'documents.edit';
    expect(fn () => $this->user->{$method}($name, team: $this->a))->toThrow(DomainException::class, 'membership');
})->with(['grantRole', 'grantPermission']);

it('removes scoped grants permanently and does not restore them on rejoining', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->user->grantRole('editor', team: $this->a);
    $this->user->grantPermission('documents.read', team: $this->a);
    $this->user->grantRole('admin');
    $this->access->removeMember($this->a, $this->user);
    $this->access->removeMember($this->a, $this->user);
    expect($this->user->hasTeam($this->a))->toBeFalse()
        ->and(DB::table('moztopia_access_permission_assignments')->count())->toBe(0);
    $this->access->addMember($this->a, $this->user);
    expect($this->user->hasRole('editor', team: $this->a))->toBeFalse()
        ->and($this->user->hasPermission('documents.read', team: $this->a))->toBeFalse()
        ->and($this->user->hasRole('admin', team: $this->a))->toBeTrue();
});

it('gives membership no permissions and paginates active teams', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->access->addMember($this->a, $this->user);
    expect($this->user->hasPermission('documents.edit', team: $this->a))->toBeFalse()
        ->and($this->user->teams()->paginate(25)->total())->toBe(1);
    $this->access->removeMember($this->a, $this->user);
    expect($this->user->teams()->count())->toBe(0);
});

it('makes repeated grants and absent revocations safe in each scope', function (?string $scope): void {
    $team = $scope === null ? null : $this->a;
    if ($team !== null) {
        $this->access->addMember($team, $this->user);
    }
    foreach (range(1, 3) as $attempt) {
        $this->user->grantRole('editor', team: $team);
        $this->user->grantPermission('documents.edit', team: $team);
    }
    expect(DB::table('moztopia_access_role_assignments')->count())->toBe(1)
        ->and(DB::table('moztopia_access_permission_assignments')->count())->toBe(1);
    foreach (range(1, 3) as $attempt) {
        $this->user->revokeRole('editor', team: $team);
        $this->user->revokePermission('documents.edit', team: $team);
    }
    expect($this->user->hasPermission('documents.edit', team: $team))->toBeFalse();
})->with([null, 'team']);

it('fails clearly on unknown mutations and fails closed on checks', function (): void {
    foreach (['grantRole', 'revokeRole', 'grantPermission', 'revokePermission'] as $method) {
        expect(fn () => $this->user->{$method}('unknown'))->toThrow(DomainException::class, 'Unknown');
    }
    expect($this->user->hasRole('unknown'))->toBeFalse()
        ->and($this->user->hasPermission('unknown'))->toBeFalse();
    $this->user->revokeRole('editor', team: $this->a);
});

it('updates role permission sets atomically and refreshes checks on reused objects', function (): void {
    $this->user->grantRole('editor');
    $service = $this->access;
    expect($this->user->hasPermission('documents.edit'))->toBeTrue();
    $service->setRolePermissions('editor', ['documents.read', 'documents.read']);
    expect($this->user->hasPermission('documents.edit'))->toBeFalse()
        ->and($this->user->hasPermission('documents.read'))->toBeTrue();
    expect(fn () => $service->setRolePermissions('editor', ['documents.edit', 'unknown']))->toThrow(DomainException::class);
    expect($this->user->hasPermission('documents.read'))->toBeTrue()
        ->and($this->user->hasPermission('documents.edit'))->toBeFalse();
    $service->defineRole('editor', []);
    expect($this->user->hasPermission('documents.read'))->toBeFalse();
});

it('inspects ordered deduplicated effective permissions and assigned roles', function (): void {
    $this->user->grantRole('editor');
    $this->user->grantPermission('documents.edit');
    $this->user->grantPermission('documents.read');
    expect($this->access->roles($this->user)->pluck('name')->all())->toBe(['editor'])
        ->and($this->access->effectivePermissions($this->user)->pluck('name')->all())->toBe(['documents.edit', 'documents.read'])
        ->and($this->access->effectivePermissions($this->user, $this->a))->toBeEmpty();
});

it('uses UUIDv7 definitions and UUID host relationships', function (): void {
    foreach ([$this->user, $this->a, AccessPermission::first(), AccessRole::first()] as $model) {
        expect(Str::isUuid($model->id, version: 7))->toBeTrue();
    }
    $this->access->addMember($this->a, $this->user);
    expect($this->user->teams()->first()->id)->toBe($this->a->id);
});

it('enforces duplicate assignment uniqueness at the database boundary', function (?string $scope, string $kind): void {
    $team = $scope === null ? null : $this->a;
    if ($team !== null) {
        $this->access->addMember($team, $this->user);
    }
    $id = $kind === 'role' ? AccessRole::where('name', 'editor')->value('id') : AccessPermission::where('name', 'documents.edit')->value('id');
    $row = ['user_id' => $this->user->id, $kind.'_id' => $id, 'team_id' => $team?->id];
    DB::table('moztopia_access_'.$kind.'_assignments')->insert($row);
    expect(fn () => DB::table('moztopia_access_'.$kind.'_assignments')->insert($row))->toThrow(QueryException::class);
})->with([null, 'team'])->with(['role', 'permission']);

it('enforces membership foreign keys even for raw SQL assignments', function (): void {
    expect(fn () => DB::table('moztopia_access_role_assignments')->insert([
        'user_id' => $this->user->id, 'role_id' => AccessRole::first()->id, 'team_id' => $this->a->id,
    ]))->toThrow(QueryException::class);
});

it('rejects nonexistent users and forged generated scope identities at the database boundary', function (): void {
    $role = AccessRole::first();
    expect(fn () => DB::table('moztopia_access_role_assignments')->insert([
        'user_id' => (string) Str::uuid7(), 'role_id' => $role->id, 'team_id' => null,
    ]))->toThrow(QueryException::class);
    expect(fn () => DB::table('moztopia_access_role_assignments')->insert([
        'user_id' => $this->user->id, 'role_id' => $role->id, 'team_id' => null, 'scope_key' => $this->a->id,
    ]))->toThrow(QueryException::class);
});

it('cleans dependent grants on permission role team and user deletion', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->user->grantRole('editor');
    $this->user->grantRole('editor', team: $this->a);
    $this->user->grantPermission('documents.read', team: $this->a);
    $this->access->deletePermission('documents.edit');
    expect($this->user->hasPermission('documents.edit'))->toBeFalse()
        ->and(DB::table('moztopia_access_role_permissions')->count())->toBe(0);
    $this->access->deleteRole('editor');
    expect(DB::table('moztopia_access_role_assignments')->count())->toBe(0);
    $this->access->deleteTeam($this->a);
    expect(DB::table('moztopia_access_memberships')->count())->toBe(0)
        ->and(DB::table('moztopia_access_permission_assignments')->count())->toBe(0);
    $this->user->grantRole('admin');
    $this->user->grantPermission('documents.read');
    $this->user->delete();
    expect(DB::table('moztopia_access_role_assignments')->count())->toBe(0)
        ->and(DB::table('moztopia_access_permission_assignments')->count())->toBe(0);
});

it('keeps global grants when a team is deleted and direct grants when a role is deleted', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->user->grantRole('editor', team: $this->a);
    $this->user->grantPermission('documents.edit');
    $this->access->deleteRole('editor');
    $this->access->deleteTeam($this->a);
    expect($this->user->hasPermission('documents.edit'))->toBeTrue()
        ->and($this->user->hasPermission('documents.edit', team: $this->a))->toBeFalse();
});

it('validates definition and team names and keeps definitions idempotent', function (): void {
    foreach (['', 'Admin', 'has space', str_repeat('a', 129)] as $name) {
        expect(fn () => $this->access->definePermission($name))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $this->access->createTeam('  '))->toThrow(InvalidArgumentException::class);
    expect($this->access->definePermission('documents.edit')->id)->toBe(AccessPermission::where('name', 'documents.edit')->value('id'));
    expect($this->access->updateTeam($this->a, 'New name')->display_name)->toBe('New name');
});

it('fails closed on unsaved users and teams and rejects invalid mutation targets', function (): void {
    $user = new AccessTestUser();
    expect($user->hasPermission('documents.edit'))->toBeFalse()
        ->and($user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasTeam(new AccessTeam()))->toBeFalse();
    expect(fn () => $user->grantRole('editor'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->access->addMember(new AccessTeam(), $this->user))->toThrow(InvalidArgumentException::class);
});

it('retains no user team or request state in a reused service', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->user->grantPermission('documents.edit', team: $this->a);
    foreach (range(1, 3) as $iteration) {
        expect($this->access->hasPermission($this->user, 'documents.edit', $this->a))->toBeTrue()
            ->and($this->access->hasPermission($this->other, 'documents.edit', $this->a))->toBeFalse()
            ->and($this->access->hasPermission($this->user, 'documents.edit', $this->b))->toBeFalse()
            ->and($this->access->hasPermission($this->user, 'documents.edit'))->toBeFalse();
    }
    $this->user->revokePermission('documents.edit', team: $this->a);
    expect($this->access->hasPermission($this->user, 'documents.edit', $this->a))->toBeFalse();
});

it('preserves host Gate policy precedence and explicit team context', function (): void {
    Gate::define('documents.edit', fn (AccessTestUser $actor, AccessTeam $team): bool => $actor->hasPermission('documents.edit', team: $team));
    $this->access->addMember($this->a, $this->user);
    $this->user->grantRole('editor', team: $this->a);
    $this->user->grantRole('admin');
    Gate::define('host.denied', fn (): bool => false);
    expect(Gate::forUser($this->user)->allows('documents.edit', $this->a))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('documents.edit', $this->b))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('host.denied'))->toBeFalse();
    Gate::policy(AccessTeam::class, new class () {
        /**
         * Require explicitly scoped host authorization.
         */

        public function update(AccessTestUser $actor, AccessTeam $team): bool
        {
            return $actor->hasPermission('documents.edit', team: $team);
        }
    }::class);
    expect(Gate::forUser($this->user)->allows('update', $this->a))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('update', $this->b))->toBeFalse();
});

it('registers publishable resources without loading migrations or automatic Gates', function (): void {
    $paths = AccessServiceProvider::pathsToPublish(AccessServiceProvider::class, 'moztopia-access-migrations');
    expect($paths)->toHaveCount(1)
        ->and(AccessServiceProvider::pathsToPublish(AccessServiceProvider::class, 'moztopia-access-config'))->toHaveCount(1)
        ->and(Gate::abilities())->toBeEmpty();
});

it('rolls back all owned tables in dependency order', function (): void {
    (require __DIR__.'/../../database/migrations/2026_11_09_000001_create_moztopia_access_tables.php')->down();
    expect(Schema::hasTable('moztopia_access_roles'))->toBeFalse()
        ->and(Schema::hasTable('access_test_users'))->toBeTrue();
});

it('keeps generated scope keys stable with MariaDB character padding modes', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('MariaDB-specific SQL mode regression.');
    }
    $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
    try {
        DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,PAD_CHAR_TO_FULL_LENGTH'");
        $this->access->addMember($this->a, $this->user);
        $this->user->grantRole('editor', team: $this->a);
        $this->user->grantRole('editor');
        expect(DB::table('moztopia_access_role_assignments')->whereNotNull('team_id')->value('scope_key'))->toBe($this->a->id)
            ->and(DB::table('moztopia_access_role_assignments')->whereNull('team_id')->value('scope_key'))->toBe('00000000-0000-0000-0000-000000000000');
    } finally {
        DB::statement('SET SESSION sql_mode = ?', [$mode]);
    }
});

it('authorizes the HTTP actor independently of a privileged mutation target', function (): void {
    Gate::policy(AccessTeam::class, Moztopia\Access\Tests\AccessTestTeamPolicy::class);
    Illuminate\Support\Facades\Route::post('/test/teams/{team}/editors/{target}', [Moztopia\Access\Tests\AccessTestTeamEditorController::class, 'store'])
        ->middleware(Illuminate\Routing\Middleware\SubstituteBindings::class);
    $this->access->definePermission('access.manage');
    $this->access->addMember($this->a, $this->user);
    $this->access->addMember($this->a, $this->other);
    $this->user->grantRole('admin');
    $this->user->grantPermission('access.manage', team: $this->a);
    $url = '/test/teams/'.$this->a->id.'/editors/'.$this->user->id;
    $this->actingAs($this->other)->postJson($url)->assertForbidden();
    expect($this->user->hasRole('editor', team: $this->a))->toBeFalse();
    $this->other->grantPermission('access.manage', team: $this->a);
    $this->actingAs($this->other)->postJson($url)->assertNoContent();
    expect($this->user->hasRole('editor', team: $this->a))->toBeTrue();
    $this->access->removeMember($this->a, $this->other);
    $this->actingAs($this->other)->postJson($url)->assertForbidden();
});

it('rolls back a new role when its permission set contains an unknown ability', function (): void {
    expect(fn () => $this->access->defineRole('reviewer', ['documents.edit', 'missing']))->toThrow(DomainException::class);
    expect(AccessRole::where('name', 'reviewer')->exists())->toBeFalse();
});

it('rejects cross-connection identities without granting or exposing access', function (): void {
    $this->user->grantRole('editor');
    $foreign = clone $this->user;
    config(['database.connections.foreign' => config('database.connections.testing')]);
    $foreign->setConnection('foreign');
    expect($foreign->hasRole('editor'))->toBeFalse()
        ->and($foreign->hasPermission('documents.edit'))->toBeFalse();
    expect(fn () => $foreign->grantRole('admin'))->toThrow(InvalidArgumentException::class);
});

it('removes direct global and team grants when their permission definition is deleted', function (): void {
    $this->access->addMember($this->a, $this->user);
    $this->user->grantPermission('documents.edit');
    $this->user->grantPermission('documents.edit', team: $this->a);
    $this->access->deletePermission('documents.edit');
    expect(DB::table('moztopia_access_permission_assignments')->count())->toBe(0)
        ->and($this->user->hasPermission('documents.edit'))->toBeFalse();
});

it('treats definition names as exact lowercase names on every engine', function (): void {
    $this->user->grantRole('editor');
    expect($this->user->hasRole('Editor'))->toBeFalse()
        ->and($this->user->hasPermission('Documents.Edit'))->toBeFalse();
    expect(fn () => $this->user->grantRole('Editor'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->user->grantPermission('Documents.Edit'))->toThrow(InvalidArgumentException::class);
});

it('publishes installable configuration and migrations through native Artisan tags', function (): void {
    $directory = sys_get_temp_dir().'/access-publication-'.Str::uuid7();
    $originalConfig = $this->app->configPath();
    $originalDatabase = $this->app->databasePath();
    try {
        $this->app->useConfigPath($directory.'/config');
        $this->app->useDatabasePath($directory.'/database');
        (new AccessServiceProvider($this->app))->boot();
        $this->artisan('vendor:publish', ['--tag' => 'moztopia-access-config', '--force' => true])->assertSuccessful();
        $this->artisan('vendor:publish', ['--tag' => 'moztopia-access-migrations', '--force' => true])->assertSuccessful();
        expect(file_get_contents($directory.'/config/moztopia-access.php'))->toBe(file_get_contents(__DIR__.'/../../config/moztopia-access.php'));
        $published = glob($directory.'/database/migrations/*create_moztopia_access_tables.php');
        expect($published)->toHaveCount(1);
        (require __DIR__.'/../../database/migrations/2026_11_09_000001_create_moztopia_access_tables.php')->down();
        (require $published[0])->up();
        expect(Schema::hasTable('moztopia_access_permission_assignments'))->toBeTrue();
    } finally {
        $this->app->useConfigPath($originalConfig);
        $this->app->useDatabasePath($originalDatabase);
        Illuminate\Support\Facades\File::deleteDirectory($directory);
    }
});

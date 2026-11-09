<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Moztopia\Access\Models\AccessTeam;
use Moztopia\Access\Services\AccessService;
use Moztopia\Access\Tests\AccessTestUser;

it('executes a concurrency worker', function (): void {
    if (getenv('ACCESS_WORKER') !== '1') {
        $this->markTestSkipped('Only executed by independent-process concurrency tests.');
    }
    $user = AccessTestUser::findOrFail(getenv('ACCESS_WORKER_USER'));
    $team = getenv('ACCESS_WORKER_TEAM') ? AccessTeam::findOrFail(getenv('ACCESS_WORKER_TEAM')) : null;
    if (getenv('ACCESS_WORKER_NAMED_TEAM') === '1' && $team !== null) {
        $team = $team->name;
    }
    file_put_contents(getenv('ACCESS_WORKER_READY'), 'ready');
    $deadline = microtime(true) + 15;
    while (!file_exists(getenv('ACCESS_WORKER_START'))) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker start barrier timed out.');
        }
        usleep(10000);
    }
    try {
        match (getenv('ACCESS_WORKER_ACTION')) {
            'grant' => $user->assignRole('editor', team: $team),
            'permission' => $user->assignPermission('documents.edit', team: $team),
            'remove' => app(AccessService::class)->removeMember($team, $user),
            'add' => app(AccessService::class)->addMember($team, $user),
        };
        expect(getenv('ACCESS_WORKER_REJECT'))->not->toBe('1');
    } catch (DomainException $error) {
        expect(getenv('ACCESS_WORKER_REJECT'))->toBe('1');
    }
});

beforeEach(function (): void {
    if (getenv('ACCESS_WORKER') === '1') {
        return;
    }
    $this->temporaryDatabase = null;
    if (DB::getDriverName() === 'sqlite') {
        $this->temporaryDatabase = tempnam(sys_get_temp_dir(), 'access-database-');
        config(['database.connections.testing.database' => $this->temporaryDatabase]);
        DB::purge('testing');
        $migration = require __DIR__.'/../../database/migrations/2026_11_09_000001_create_moztopia_access_tables.php';
        Illuminate\Support\Facades\Schema::create('access_test_users', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
        $migration->up();
    }
    $this->processes = [];
    $this->barriers = [];
    $this->access = app(AccessService::class);
    $this->user = AccessTestUser::create();
    $this->team = $this->access->createTeam('concurrent-team');
    $this->access->definePermission('documents.edit');
    $this->access->defineRole('editor', ['documents.edit']);
    $this->access->addMember($this->team, $this->user);
    $this->spawn = function (string $action, ?AccessTeam $team = null, bool $reject = false): int {
        $ready = tempnam(sys_get_temp_dir(), 'access-ready-');
        $start = tempnam(sys_get_temp_dir(), 'access-start-');
        unlink($ready);
        unlink($start);
        $this->barriers[] = $ready;
        $this->barriers[] = $start;
        $env = array_merge(getenv(), [
            'ACCESS_WORKER' => '1',
            'ACCESS_WORKER_USER' => $this->user->id,
            'ACCESS_WORKER_TEAM' => $team?->id ?? '',
            'ACCESS_WORKER_NAMED_TEAM' => '1',
            'ACCESS_WORKER_ACTION' => $action,
            'ACCESS_WORKER_REJECT' => $reject ? '1' : '0',
            'ACCESS_WORKER_READY' => $ready,
            'ACCESS_WORKER_START' => $start,
            'ACCESS_DB_DATABASE' => config('database.connections.testing.database'),
        ]);
        $process = proc_open([PHP_BINARY, __DIR__.'/../../vendor/pestphp/pest/bin/pest', __FILE__, '--filter=executes a concurrency worker', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->processes[] = [$process, $pipes];
        $deadline = microtime(true) + 10;
        while (!file_exists($ready)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker readiness timed out.');
            }
            usleep(10000);
        }
        file_put_contents($start, 'start');

        return array_key_last($this->processes);
    };
    $this->finish = function (int $index): void {
        [$process, $pipes] = $this->processes[$index];
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unset($this->processes[$index]);
        expect($exit, $stdout.$stderr)->toBe(0);
    };
});

afterEach(function (): void {
    if (getenv('ACCESS_WORKER') === '1') {
        return;
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($this->processes ?? [] as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
    foreach ($this->barriers ?? [] as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
    if ($this->temporaryDatabase !== null) {
        DB::disconnect('testing');
        unlink($this->temporaryDatabase);
    }
});

it('serializes duplicate grants from independent processes', function (?string $scope, string $action): void {
    $team = $scope === null ? null : $this->team;
    DB::beginTransaction();
    $method = $action === 'grant' ? 'assignRole' : 'assignPermission';
    $name = $action === 'grant' ? 'editor' : 'documents.edit';
    $this->user->{$method}($name, team: $team);
    $first = ($this->spawn)($action, $team);
    $second = ($this->spawn)($action, $team);
    usleep(100000);
    expect(proc_get_status($this->processes[$first][0])['running'])->toBeTrue()
        ->and(proc_get_status($this->processes[$second][0])['running'])->toBeTrue();
    DB::commit();
    ($this->finish)($first);
    ($this->finish)($second);
    $kind = $action === 'grant' ? 'role' : 'permission';
    expect(DB::table('moztopia_access_'.$kind.'_assignments')->count())->toBe(1);
})->with([null, 'team'])->with(['grant', 'permission']);

it('rejects a blocked grant after membership removal commits', function (): void {
    DB::beginTransaction();
    $this->access->removeMember($this->team, $this->user);
    $process = ($this->spawn)('grant', $this->team, true);
    usleep(100000);
    expect(proc_get_status($this->processes[$process][0])['running'])->toBeTrue();
    DB::commit();
    ($this->finish)($process);
    expect(DB::table('moztopia_access_role_assignments')->count())->toBe(0);
});

it('cascades a committed grant when a blocked membership removal proceeds', function (): void {
    DB::beginTransaction();
    $this->user->assignRole('editor', team: $this->team);
    $process = ($this->spawn)('remove', $this->team);
    usleep(100000);
    expect(proc_get_status($this->processes[$process][0])['running'])->toBeTrue();
    DB::commit();
    ($this->finish)($process);
    expect($this->user->hasTeam($this->team))->toBeFalse()
        ->and(DB::table('moztopia_access_role_assignments')->count())->toBe(0);
    $this->access->addMember($this->team, $this->user);
    expect($this->user->hasRole('editor', team: $this->team))->toBeFalse();
});

it('serializes duplicate membership additions', function (): void {
    $this->access->removeMember($this->team, $this->user);
    DB::beginTransaction();
    $this->access->addMember($this->team, $this->user);
    $process = ($this->spawn)('add', $this->team);
    usleep(100000);
    expect(proc_get_status($this->processes[$process][0])['running'])->toBeTrue();
    DB::commit();
    ($this->finish)($process);
    expect(DB::table('moztopia_access_memberships')->count())->toBe(1);
});

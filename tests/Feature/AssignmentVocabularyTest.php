<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Moztopia\Access\Models\AccessTeam;
use Moztopia\Access\Services\AccessService;
use Moztopia\Access\Tests\AccessTestUser;

beforeEach(function (): void {
    $this->access = app(AccessService::class);
    $this->user = AccessTestUser::create();
    $this->other = AccessTestUser::create();
    $this->team = $this->access->createTeam('engineering', displayName: 'Engineering');
    $this->otherTeam = $this->access->createTeam('support');
    $this->access->definePermission('documents.edit');
    $this->access->defineRole('editor', ['documents.edit']);
});

it('keeps every permission spelling equivalent across global object and named scopes', function (string $method, string $scope): void {
    $team = match ($scope) {
        'global' => null,
        'object' => $this->team,
        'name' => 'engineering',
    };
    if ($team !== null) {
        $this->user->assignTeam($team);
    }
    $this->user->{$method}('documents.edit', team: $team);
    $this->user->assignPermission('documents.edit', team: $team);
    expect(DB::table('moztopia_access_permission_assignments')->count())->toBe(1)
        ->and($this->user->hasPermission('documents.edit', team: $team))->toBeTrue()
        ->and($this->other->hasPermission('documents.edit', team: $team))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit', team: 'support'))->toBeFalse();
    $this->user->revokePermission('documents.edit', team: $team);
    expect($this->user->hasPermission('documents.edit', team: $team))->toBeFalse();
})->with(['assignPermission', 'grantPermission', 'givePermission', 'allow'])->with(['global', 'object', 'name']);

it('keeps role assignment spellings equivalent for names and objects', function (string $method, string $scope): void {
    $team = match ($scope) {
        'global' => null,
        'object' => $this->team,
        'name' => 'engineering',
    };
    if ($team !== null) {
        $this->user->assignTeam($team);
    }
    $this->user->{$method}('editor', team: $team);
    $this->user->assignRole('editor', team: $team);
    expect(DB::table('moztopia_access_role_assignments')->count())->toBe(1)
        ->and($this->user->hasRole('editor', team: $team))->toBeTrue()
        ->and($this->user->hasPermission('documents.edit', team: $team))->toBeTrue();
    $this->user->revokeRole('editor', team: $team);
    expect($this->user->hasPermission('documents.edit', team: $team))->toBeFalse();
})->with(['assignRole', 'grantRole'])->with(['global', 'object', 'name']);

it('enforces identical membership and unknown-definition rules for all spellings', function (string $method, string $name): void {
    expect(fn () => $this->user->{$method}($name, team: 'engineering'))->toThrow(DomainException::class, 'membership');
    $this->user->assignTeam('engineering');
    expect(fn () => $this->user->{$method}('missing', team: 'engineering'))->toThrow(DomainException::class, 'Unknown');
    expect(DB::table('moztopia_access_role_assignments')->count())->toBe(0)
        ->and(DB::table('moztopia_access_permission_assignments')->count())->toBe(0);
})->with([
    ['assignRole', 'editor'], ['grantRole', 'editor'],
    ['assignPermission', 'documents.edit'], ['grantPermission', 'documents.edit'],
    ['givePermission', 'documents.edit'], ['allow', 'documents.edit'],
]);

it('assigns named membership only and retains UUID identity across label updates', function (): void {
    $this->user->assignTeam('engineering');
    $this->user->assignTeam($this->team);
    $updated = $this->access->updateTeam('engineering', displayName: 'Product Engineering');
    expect($updated->id)->toBe($this->team->id)
        ->and($updated->name)->toBe('engineering')
        ->and($updated->display_name)->toBe('Product Engineering')
        ->and($this->user->hasTeam('engineering'))->toBeTrue()
        ->and($this->user->teams()->paginate(25)->total())->toBe(1)
        ->and($this->user->hasPermission('documents.edit', team: 'engineering'))->toBeFalse();
});

it('does not create unknown teams and fails closed for unknown or malformed scopes', function (): void {
    expect(fn () => $this->user->assignTeam('missing'))->toThrow(DomainException::class, 'Unknown team');
    expect(fn () => $this->user->assignRole('editor', team: 'missing'))->toThrow(DomainException::class, 'Unknown team');
    expect($this->user->hasTeam('missing'))->toBeFalse()
        ->and($this->user->hasRole('editor', team: 'missing'))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit', team: 'Engineering'))->toBeFalse()
        ->and($this->access->effectivePermissions($this->user, 'missing'))->toBeEmpty()
        ->and(AccessTeam::count())->toBe(2);
});

it('removes named membership and grants permanently without affecting other scopes', function (): void {
    $this->user->assignTeam('engineering');
    $this->user->assignRole('editor', team: 'engineering');
    $this->user->assignPermission('documents.edit');
    $this->access->removeMember('engineering', $this->user);
    expect($this->user->hasTeam('engineering'))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit', team: 'engineering'))->toBeFalse();
    $this->user->assignTeam('engineering');
    expect($this->user->hasRole('editor', team: 'engineering'))->toBeFalse()
        ->and($this->user->hasPermission('documents.edit', team: 'engineering'))->toBeTrue();
    $this->access->deleteTeam('engineering');
    expect($this->user->hasPermission('documents.edit'))->toBeTrue()
        ->and($this->user->hasTeam('engineering'))->toBeFalse();
});

it('enforces unique stable team names at the database boundary', function (): void {
    expect(fn () => $this->access->createTeam('engineering', displayName: 'Another label'))->toThrow(QueryException::class);
    expect($this->access->createTeam('different-name', displayName: 'Engineering')->name)->toBe('different-name');
});

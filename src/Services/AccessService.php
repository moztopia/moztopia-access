<?php

declare(strict_types=1);

namespace Moztopia\Access\Services;

use Closure;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Moztopia\Access\Models\AccessPermission;
use Moztopia\Access\Models\AccessRole;
use Moztopia\Access\Models\AccessTeam;

/**
 * Manage trusted Access mutations and fresh, explicitly scoped checks.
 *
 * The host must authorize the actor before calling mutation methods.
 */

final class AccessService
{
    /**
     * Define a named permission idempotently.
     */

    public function definePermission(string $name): AccessPermission
    {
        $this->validateName($name);

        return $this->transaction(fn () => AccessPermission::firstOrCreate(['name' => $name]));
    }

    /**
     * Define a role and replace its complete permission set atomically.
     *
     * @param list<string> $permissions
     */

    public function defineRole(string $name, array $permissions = []): AccessRole
    {
        $this->validateName($name);

        return $this->transaction(function () use ($name, $permissions): AccessRole {
            $role = AccessRole::firstOrCreate(['name' => $name]);
            $this->setRolePermissions($name, $permissions);

            return $role;
        });
    }

    /**
     * Replace a known role's permissions, rejecting unknown definitions.
     *
     * @param list<string> $permissions
     */

    public function setRolePermissions(string $name, array $permissions): void
    {
        $this->transaction(function () use ($name, $permissions): void {
            $role = $this->definition('role', $name);
            $ids = [];
            foreach ($permissions as $permission) {
                if (!is_string($permission)) {
                    throw new InvalidArgumentException('Permission names must be strings.');
                }
                $ids[] = $this->definition('permission', $permission)->id;
            }
            DB::table('moztopia_access_role_permissions')->where('role_id', $role->id)->delete();
            foreach (array_unique($ids) as $id) {
                DB::table('moztopia_access_role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $id]);
            }
        });
    }

    /**
     * Create a uniquely named team with an optional display label.
     */

    public function createTeam(string $name, ?string $displayName = null): AccessTeam
    {
        $this->validateName($name);
        $displayName ??= $name;
        $this->validateTeamName($displayName);

        return $this->transaction(fn () => AccessTeam::create(['name' => $name, 'display_name' => $displayName]));
    }

    /**
     * Update a team's display name without changing its identity.
     */

    public function updateTeam(AccessTeam|string $team, string $displayName): AccessTeam
    {
        $this->validateTeamName($displayName);

        return $this->transaction(function () use ($team, $displayName): AccessTeam {
            $stored = $this->lockTeam($team);
            $stored->update(['display_name' => $displayName]);

            return $stored;
        });
    }

    /**
     * Add active membership idempotently without granting access.
     */

    public function addMember(AccessTeam|string $team, Model $user): void
    {
        $this->transaction(function () use ($team, $user): void {
            $this->lockUser($user);
            $team = $this->lockTeam($team);
            DB::table('moztopia_access_memberships')->upsert(
                [['team_id' => $team->getKey(), 'user_id' => $user->getKey()]],
                ['team_id', 'user_id'],
                ['user_id'],
            );
        });
    }

    /**
     * Remove membership and cascade all scoped assignments permanently.
     */

    public function removeMember(AccessTeam|string $team, Model $user): void
    {
        $this->transaction(function () use ($team, $user): void {
            $this->lockUser($user);
            $team = $this->lockTeam($team);
            DB::table('moztopia_access_memberships')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->delete();
        });
    }

    /**
     * Grant a known role to a persisted user.
     */

    public function assignRole(Model $user, string $name, AccessTeam|string|null $team = null): void
    {
        $this->assign('role', $user, $name, $team, true);
    }

    /**
     * Alias assignRole for the original service contract.
     */

    public function grantRole(Model $user, string $name, AccessTeam|string|null $team = null): void
    {
        $this->assignRole($user, $name, $team);
    }

    /**
     * Revoke only this role assignment; unknown roles are errors.
     */

    public function revokeRole(Model $user, string $name, AccessTeam|string|null $team = null): void
    {
        $this->assign('role', $user, $name, $team, false);
    }

    /**
     * Grant a known direct permission to a persisted user.
     */

    public function assignPermission(Model $user, string $name, AccessTeam|string|null $team = null): void
    {
        $this->assign('permission', $user, $name, $team, true);
    }

    /**
     * Alias assignPermission for the original service contract.
     */

    public function grantPermission(Model $user, string $name, AccessTeam|string|null $team = null): void
    {
        $this->assignPermission($user, $name, $team);
    }

    /**
     * Revoke only this direct permission; inherited access is unaffected.
     */

    public function revokePermission(Model $user, string $name, AccessTeam|string|null $team = null): void
    {
        $this->assign('permission', $user, $name, $team, false);
    }

    /**
     * Check assigned roles, including global roles in a valid team scope.
     */

    public function hasRole(Model $user, string $name, AccessTeam|string|null $team = null): bool
    {
        return $this->roles($user, $team)->contains('name', $name);
    }

    /**
     * Check effective permissions, failing closed for unknown names.
     */

    public function hasPermission(Model $user, string $name, AccessTeam|string|null $team = null): bool
    {
        return $this->effectivePermissions($user, $team)->contains('name', $name);
    }

    /**
     * Check active membership from the database on every call.
     */

    public function hasTeam(Model $user, AccessTeam|string $team): bool
    {
        $team = $this->findTeam($team);

        return $team !== null && $this->validUser($user)
            && DB::table('moztopia_access_memberships')->where('user_id', $user->getKey())->where('team_id', $team->getKey())->exists();
    }

    /**
     * Inspect assigned roles for the requested scope.
     *
     * @return Collection<int, AccessRole>
     */

    public function roles(Model $user, AccessTeam|string|null $team = null): Collection
    {
        if (!$this->validUser($user)) {
            return collect();
        }
        if ($team !== null) {
            $team = $this->findTeam($team);
            if ($team === null) {
                return collect();
            }
        }

        return AccessRole::query()->whereExists(function (Builder $query) use ($user, $team): void {
            $query->selectRaw('1')->from('moztopia_access_role_assignments as assignments')
                ->whereColumn('assignments.role_id', 'moztopia_access_roles.id');
            $this->scope($query, $user, $team);
        })->orderBy('name')->get();
    }

    /**
     * Inspect the union of direct and role-derived permissions.
     *
     * @return Collection<int, AccessPermission>
     */

    public function effectivePermissions(Model $user, AccessTeam|string|null $team = null): Collection
    {
        if (!$this->validUser($user)) {
            return collect();
        }
        if ($team !== null) {
            $team = $this->findTeam($team);
            if ($team === null) {
                return collect();
            }
        }

        return AccessPermission::query()->where(function ($permissions) use ($user, $team): void {
            $permissions->whereExists(function (Builder $query) use ($user, $team): void {
                $query->selectRaw('1')->from('moztopia_access_permission_assignments as assignments')
                    ->whereColumn('assignments.permission_id', 'moztopia_access_permissions.id');
                $this->scope($query, $user, $team);
            })->orWhereExists(function (Builder $query) use ($user, $team): void {
                $query->selectRaw('1')->from('moztopia_access_role_assignments as assignments')
                    ->join('moztopia_access_role_permissions as inherited', 'inherited.role_id', '=', 'assignments.role_id')
                    ->whereColumn('inherited.permission_id', 'moztopia_access_permissions.id');
                $this->scope($query, $user, $team);
            });
        })->orderBy('name')->get();
    }

    /**
     * Delete a known role and cascade its grants and permission links.
     */

    public function deleteRole(string $name): void
    {
        $this->transaction(fn () => $this->definition('role', $name)->delete());
    }

    /**
     * Delete a known permission and cascade direct grants and role links.
     */

    public function deletePermission(string $name): void
    {
        $this->transaction(fn () => $this->definition('permission', $name)->delete());
    }

    /**
     * Delete a team, its memberships and all team-scoped grants.
     */

    public function deleteTeam(AccessTeam|string $team): void
    {
        $this->transaction(fn () => $this->lockTeam($team)->delete());
    }

    /**
     * Mutate one exact scope under the user and team serialization locks.
     */

    private function assign(string $kind, Model $user, string $name, AccessTeam|string|null $team, bool $grant): void
    {
        $this->transaction(function () use ($kind, $user, $name, $team, $grant): void {
            $this->lockUser($user);
            if ($team !== null) {
                $team = $this->lockTeam($team);
            }
            $definition = $this->definition($kind, $name);
            if ($grant && $team !== null && !$this->hasTeam($user, $team)) {
                throw new DomainException('Active team membership is required before granting access.');
            }
            $values = ['user_id' => $user->getKey(), $kind.'_id' => $definition->id, 'team_id' => $team?->getKey()];
            $table = DB::table('moztopia_access_'.$kind.'_assignments');
            if ($grant) {
                $table->upsert([$values], ['user_id', 'scope_key', $kind.'_id'], [$kind.'_id']);
            } else {
                $table->where($values)->delete();
            }
        });
    }

    /**
     * Constrain a single SQL statement to a user and exact effective scope.
     */

    private function scope(Builder $query, Model $user, ?AccessTeam $team): void
    {
        $query->where('assignments.user_id', $user->getKey());
        if ($team === null) {
            $query->whereNull('assignments.team_id');

            return;
        }
        $query->where(function (Builder $scope) use ($team): void {
            $scope->whereNull('assignments.team_id')->orWhere('assignments.team_id', $team->getKey());
        })->whereExists(function (Builder $membership) use ($user, $team): void {
            $membership->selectRaw('1')->from('moztopia_access_memberships')
                ->where('user_id', $user->getKey())->where('team_id', $team->getKey());
        });
    }

    /**
     * Resolve and lock a known application-wide definition.
     */

    private function definition(string $kind, string $name): AccessRole|AccessPermission
    {
        $this->validateName($name);
        $class = $kind === 'role' ? AccessRole::class : AccessPermission::class;
        $definition = $class::query()->where('name', $name)->lockForUpdate()->first();
        if ($definition === null) {
            throw new DomainException('Unknown '.$kind.': '.$name);
        }

        return $definition;
    }

    /**
     * Serialize membership and assignment operations for a target user.
     */

    private function lockUser(Model $user): void
    {
        if (!$this->validUser($user)) {
            throw new InvalidArgumentException('Access requires a persisted configured UUID authenticatable on the default database connection.');
        }
        $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock a persisted team to coordinate membership and deletion races.
     */

    private function lockTeam(AccessTeam|string $team): AccessTeam
    {
        if (is_string($team)) {
            $this->validateName($team);
            return AccessTeam::query()->where('name', $team)->lockForUpdate()->first()
                ?? throw new DomainException('Unknown team. Define the team before assigning membership or access.');
        }
        if (!$this->validTeam($team)) {
            throw new InvalidArgumentException('Access requires a persisted team on the default database connection.');
        }

        return AccessTeam::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Reject unsupported user identities and connection topologies.
     */

    private function validUser(Model $user): bool
    {
        $class = config('moztopia-access.user_model');

        return is_string($class) && $user instanceof $class && $user instanceof Authenticatable && $user->exists
            && Str::isUuid((string) $user->getKey()) && $user->getConnection()->getName() === DB::connection()->getName();
    }

    /**
     * Resolve a stable team name for a check without creating membership.
     */

    private function findTeam(AccessTeam|string $team): ?AccessTeam
    {
        if (is_string($team)) {
            if (preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/D', $team) !== 1) {
                return null;
            }
            $team = AccessTeam::query()->where('name', $team)->first();
        }

        return $team !== null && $this->validTeam($team) ? $team : null;
    }

    /**
     * Reject unsaved or cross-database team handles.
     */

    private function validTeam(AccessTeam $team): bool
    {
        return $team->exists && Str::isUuid((string) $team->getKey())
            && $team->getConnection()->getName() === DB::connection()->getName();
    }

    /**
     * Apply deterministic, portable definition naming rules.
     */

    private function validateName(string $name): void
    {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/D', $name) !== 1) {
            throw new InvalidArgumentException('Access names must contain 1–128 lowercase ASCII letters, digits, dots, underscores or hyphens, starting with a letter.');
        }
    }

    /**
     * Validate a nonblank bounded display name.
     */

    private function validateTeamName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 128) {
            throw new InvalidArgumentException('Team display names must contain 1–128 characters and cannot be blank.');
        }
    }

    /**
     * Retry deadlocks and establish SQLite's writer lock before any read.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */

    private function transaction(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('moztopia_access_roles')->whereRaw('1 = 0')->update(['name' => DB::raw('name')]);
            }

            return $operation();
        }, 5);
    }
}

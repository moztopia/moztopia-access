<?php

declare(strict_types=1);

namespace Moztopia\Access;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Moztopia\Access\Models\AccessTeam;
use Moztopia\Access\Services\AccessService;

/**
 * Add explicit Access assignments without overriding Laravel authorization.
 */

trait HasAccess
{
    /**
     * Assign a known role in the requested scope.
     */

    public function assignRole(string $name, AccessTeam|string|null $team = null): void
    {
        app(AccessService::class)->assignRole($this, $name, $team);
    }

    /**
     * Assign a known direct permission in the requested scope.
     */

    public function assignPermission(string $name, AccessTeam|string|null $team = null): void
    {
        app(AccessService::class)->assignPermission($this, $name, $team);
    }

    /**
     * Assign membership without granting roles or permissions.
     */

    public function assignTeam(AccessTeam|string $team): void
    {
        app(AccessService::class)->addMember($team, $this);
    }

    /**
     * Alias assignPermission for familiar permission-management vocabulary.
     */

    public function givePermission(string $name, AccessTeam|string|null $team = null): void
    {
        $this->assignPermission($name, $team);
    }

    /**
     * Alias assignPermission as a mutation, never an authorization check.
     */

    public function allow(string $name, AccessTeam|string|null $team = null): void
    {
        $this->assignPermission($name, $team);
    }

    /**
     * Alias assignRole while preserving the original public contract.
     */

    public function grantRole(string $name, AccessTeam|string|null $team = null): void
    {
        $this->assignRole($name, $team);
    }

    /**
     * Revoke only the requested role assignment.
     */

    public function revokeRole(string $name, AccessTeam|string|null $team = null): void
    {
        app(AccessService::class)->revokeRole($this, $name, $team);
    }

    /**
     * Check assigned roles with explicit scope inheritance.
     */

    public function hasRole(string $name, AccessTeam|string|null $team = null): bool
    {
        return app(AccessService::class)->hasRole($this, $name, $team);
    }

    /**
     * Alias assignPermission while preserving the original public contract.
     */

    public function grantPermission(string $name, AccessTeam|string|null $team = null): void
    {
        $this->assignPermission($name, $team);
    }

    /**
     * Revoke only the requested direct permission assignment.
     */

    public function revokePermission(string $name, AccessTeam|string|null $team = null): void
    {
        app(AccessService::class)->revokePermission($this, $name, $team);
    }

    /**
     * Check direct and role-derived permissions without retained state.
     */

    public function hasPermission(string $name, AccessTeam|string|null $team = null): bool
    {
        return app(AccessService::class)->hasPermission($this, $name, $team);
    }

    /**
     * Check current active membership.
     */

    public function hasTeam(AccessTeam|string $team): bool
    {
        return app(AccessService::class)->hasTeam($this, $team);
    }

    /**
     * Query active teams, including pagination through Eloquent.
     *
     * @return BelongsToMany<AccessTeam, $this>
     */

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(AccessTeam::class, 'moztopia_access_memberships', 'user_id', 'team_id');
    }
}

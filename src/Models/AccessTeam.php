<?php

declare(strict_types=1);

namespace Moztopia\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Represent a stable team name and its mutable display label.
 *
 * Use AccessService for supported mutations and integrity rules.
 */

final class AccessTeam extends Model
{
    use HasUuids;

    /**
     * Store definitions in the package namespace.
     */

    protected $table = 'moztopia_access_teams';

    /**
     * Permit the team name and display label in mass assignment.
     */

    protected $fillable = ['name', 'display_name'];
}

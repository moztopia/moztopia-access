<?php

declare(strict_types=1);

namespace Moztopia\Access\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Represent an application-wide Access Role definition.
 *
 * Use AccessService for supported mutations and integrity rules.
 */

final class AccessRole extends Model
{
    use HasUuids;

    /**
     * Store definitions in the package namespace.
     */

    protected $table = 'moztopia_access_roles';

    /**
     * Permit only the definition name in mass assignment.
     */

    protected $fillable = ['name'];
}

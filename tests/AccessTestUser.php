<?php

declare(strict_types=1);

namespace Moztopia\Access\Tests;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;
use Moztopia\Access\Traits\HasAccess;

/**
 * Model a host-owned UUID authenticatable.
 */

final class AccessTestUser extends User
{
    use HasAccess;
    use HasUuids;

    /**
     * Use a synthetic host table.
     */

    protected $table = 'access_test_users';
}

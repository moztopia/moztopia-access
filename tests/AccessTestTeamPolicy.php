<?php

declare(strict_types=1);

namespace Moztopia\Access\Tests;

use Moztopia\Access\Models\AccessTeam;

/**
 * Demonstrate host-owned actor authorization for a fixed role mutation.
 */

final class AccessTestTeamPolicy
{
    /**
     * Require scoped management authority and an existing target membership.
     */

    public function grantEditor(AccessTestUser $actor, AccessTeam $team, AccessTestUser $target): bool
    {
        return $actor->hasPermission('access.manage', team: $team) && $target->hasTeam($team);
    }
}

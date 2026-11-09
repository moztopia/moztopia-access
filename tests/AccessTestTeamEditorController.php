<?php

declare(strict_types=1);

namespace Moztopia\Access\Tests;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Moztopia\Access\Models\AccessTeam;

/**
 * Exercise the documented host controller boundary through HTTP.
 */

final class AccessTestTeamEditorController
{
    /**
     * Authorize the actor independently before mutating the target.
     */

    public function store(AccessTeam $team, AccessTestUser $target): Response
    {
        Gate::authorize('grantEditor', [$team, $target]);
        $target->grantRole('editor', team: $team);

        return response()->noContent();
    }
}

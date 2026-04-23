<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ClientResourcePolicy;

class AgentPersonaPolicy
{
    use ClientResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'agent_persona';
    }
}

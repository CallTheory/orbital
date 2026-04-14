<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\TenantResourcePolicy;

class AgentPersonaPolicy
{
    use TenantResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'agent_persona';
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\TenantResourcePolicy;

class RoutingRulePolicy
{
    use TenantResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'routing_rule';
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ClientResourcePolicy;

class RoutingRulePolicy
{
    use ClientResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'routing_rule';
    }
}

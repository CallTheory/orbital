<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\TenantResourcePolicy;

class CallQueuePolicy
{
    use TenantResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'call_queue';
    }
}

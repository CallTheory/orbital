<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ClientResourcePolicy;

class CallQueuePolicy
{
    use ClientResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'call_queue';
    }
}

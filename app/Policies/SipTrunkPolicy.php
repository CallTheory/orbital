<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\TenantResourcePolicy;

class SipTrunkPolicy
{
    use TenantResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'sip_trunk';
    }
}

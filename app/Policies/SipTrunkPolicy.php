<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ClientResourcePolicy;

class SipTrunkPolicy
{
    use ClientResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'sip_trunk';
    }
}

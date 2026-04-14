<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\TenantResourcePolicy;

class OperatingHourPolicy
{
    use TenantResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'operating_hour';
    }
}

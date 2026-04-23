<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ClientResourcePolicy;

class OperatingHourPolicy
{
    use ClientResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'operating_hour';
    }
}

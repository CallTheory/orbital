<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ClientResourcePolicy;

class ScriptPolicy
{
    use ClientResourcePolicy;

    protected function permissionPrefix(): string
    {
        return 'script';
    }
}

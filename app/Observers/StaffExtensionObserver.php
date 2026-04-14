<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\Telephony\PlatformExtensionAllocator;

/**
 * Cleans up a staff user's softphone extension when the user is deleted.
 *
 * The allocator is a no-op if the user doesn't have an extension assigned,
 * so this is safe for tenant-side users (who never get one) too.
 */
class StaffExtensionObserver
{
    public function __construct(
        protected PlatformExtensionAllocator $allocator,
    ) {}

    public function deleting(User $user): void
    {
        $this->allocator->removeFor($user);
    }
}

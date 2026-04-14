<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Extension;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Allocates and manages WebRTC softphone extensions for platform staff users.
 *
 * Each staff user gets exactly one WebRTC extension automatically — they
 * never see or manage it as a standalone Extension record. The credentials
 * are surfaced on their user edit page in the Staff resource.
 *
 * Numbers are sequential within the configured range (orbital.staff_extensions.base
 * to orbital.staff_extensions.max). The allocator uses a transaction + lock
 * to prevent race conditions when multiple users are created concurrently.
 */
class PlatformExtensionAllocator
{
    /**
     * Make sure $user has a WebRTC extension. Idempotent: if one already
     * exists, returns it untouched. Returns the Extension record so callers
     * can surface the credentials.
     */
    public function ensureWebrtcExtensionFor(User $user): Extension
    {
        $existing = $this->extensionFor($user);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user) {
            $number = $this->nextAvailableNumber();

            return Extension::create([
                'team_id' => null,
                'number' => $number,
                'type' => 'staff_softphone',
                'label' => $user->name,
                'assignable_type' => $user->getMorphClass(),
                'assignable_id' => $user->id,
                'sip_username' => (string) $number,
                'sip_password' => Str::password(16, symbols: false),
                'transport' => 'wss',
                'context' => 'internal',
                'is_active' => true,
            ]);
        });
    }

    /**
     * Return the WebRTC extension currently allocated to $user, if any.
     */
    public function extensionFor(User $user): ?Extension
    {
        return Extension::withoutGlobalScope('team')
            ->where('type', 'staff_softphone')
            ->whereNull('team_id')
            ->where('assignable_type', $user->getMorphClass())
            ->where('assignable_id', $user->id)
            ->first();
    }

    /**
     * Generate a fresh SIP password for $user's WebRTC extension. No-op if
     * the user doesn't have one allocated.
     */
    public function regeneratePassword(User $user): ?string
    {
        $ext = $this->extensionFor($user);
        if (! $ext) {
            return null;
        }

        $newPassword = Str::password(16, symbols: false);
        $ext->update(['sip_password' => $newPassword]);

        return $newPassword;
    }

    /**
     * Soft-delete the user's WebRTC extension. Called on user deletion.
     */
    public function removeFor(User $user): void
    {
        $ext = $this->extensionFor($user);
        $ext?->delete();
    }

    /**
     * Find the lowest unused integer in [base, max] across both active and
     * soft-deleted extensions, so we don't recycle numbers that might still
     * be referenced in call logs or call queue members.
     */
    protected function nextAvailableNumber(): int
    {
        $base = config('orbital.staff_extensions.base', 2000);
        $max = config('orbital.staff_extensions.max', 2999);

        $taken = Extension::withoutGlobalScope('team')
            ->withTrashed()
            ->whereNull('team_id')
            ->where('type', 'staff_softphone')
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->all();

        for ($n = $base; $n <= $max; $n++) {
            if (! in_array($n, $taken, true)) {
                return $n;
            }
        }

        throw new RuntimeException(
            "No available staff extension numbers in range {$base}-{$max}. ".
            'Adjust orbital.staff_extensions in config or release unused extensions.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\MessagingOptOut;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The suppression list, and the single place that answers "may we text
 * this person on behalf of this client".
 *
 * Every outbound path goes through isSuppressed() before the provider
 * is called. That check is cheap (one indexed lookup) and it is the
 * only thing standing between an operator's good intentions and a
 * message to somebody who told us to stop.
 *
 * Requiring a carrier sender pool means Twilio would refuse most of
 * these sends anyway. This layer exists so the refusal is OURS: visible
 * in the thread, understood by the AI reply job, and applied on the
 * transports that have no carrier to do it for them.
 */
class OptOutRegistry
{
    /**
     * Is this address suppressed for this client right now?
     */
    public function isSuppressed(int $teamId, string $address): bool
    {
        return $this->find($teamId, $address)?->isActive() ?? false;
    }

    /**
     * The live suppression for an address, or null.
     */
    public function find(int $teamId, string $address): ?MessagingOptOut
    {
        $keys = MessagingOptOut::keyVariants($address);

        if ($keys === []) {
            return null;
        }

        return MessagingOptOut::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('address_key', $keys)
            // A live suppression outranks a lifted one when an address
            // somehow keyed two ways; refusing to send is the safe side
            // of that coin.
            ->orderByRaw('CASE WHEN opted_in_at IS NULL THEN 0 ELSE 1 END')
            ->first();
    }

    /**
     * Record an opt-out. Idempotent: a customer who sends STOP three
     * times has opted out once.
     */
    public function optOut(
        int $teamId,
        string $address,
        ?string $keyword = null,
        string $source = MessagingOptOut::SOURCE_INBOUND,
        ?MessagingEndpoint $endpoint = null,
        ?User $actor = null,
    ): MessagingOptOut {
        $record = DB::transaction(function () use ($teamId, $address, $keyword, $source, $endpoint, $actor): MessagingOptOut {
            $existing = $this->find($teamId, $address);

            if ($existing) {
                $existing->forceFill([
                    'opted_in_at' => null,
                    'keyword' => $keyword ?? $existing->keyword,
                    'source' => $source,
                    'messaging_endpoint_id' => $endpoint?->id ?? $existing->messaging_endpoint_id,
                    'created_by_user_id' => $actor?->id ?? $existing->created_by_user_id,
                ]);

                // Don't move the clock on a repeat STOP. The date that
                // matters is when they FIRST told us, and that is the
                // date a complaint will be measured against.
                if ($existing->opted_out_at === null) {
                    $existing->opted_out_at = now();
                }

                $existing->save();

                return $existing;
            }

            return MessagingOptOut::create([
                'team_id' => $teamId,
                'messaging_endpoint_id' => $endpoint?->id,
                'address' => $address,
                'address_key' => MessagingOptOut::key($address),
                'keyword' => $keyword,
                'source' => $source,
                'opted_out_at' => now(),
                'created_by_user_id' => $actor?->id,
            ]);
        });

        Log::info('messaging opt-out recorded', [
            'team_id' => $teamId,
            'address_key' => $record->address_key,
            'keyword' => $keyword,
            'source' => $source,
        ]);

        return $record;
    }

    /**
     * Lift a suppression (START/UNSTOP, or an admin acting on a written
     * request). No-op when there was nothing to lift.
     */
    public function optIn(
        int $teamId,
        string $address,
        ?string $keyword = null,
        ?User $actor = null,
    ): ?MessagingOptOut {
        $existing = $this->find($teamId, $address);

        if (! $existing || ! $existing->isActive()) {
            return $existing;
        }

        $existing->forceFill([
            'opted_in_at' => now(),
            'keyword' => $keyword ?? $existing->keyword,
            'created_by_user_id' => $actor?->id ?? $existing->created_by_user_id,
        ])->save();

        Log::info('messaging opt-out lifted', [
            'team_id' => $teamId,
            'address_key' => $existing->address_key,
        ]);

        return $existing;
    }

    /**
     * Convenience for the thread-shaped callers, which is most of them.
     */
    public function isThreadSuppressed(MessageThread $thread): bool
    {
        return $this->isSuppressed($thread->team_id, $thread->remote_address);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A number, shortcode, or pager address a client receives text-shaped
 * traffic on.
 *
 * The messaging equivalent of a DID. Kept separate from `client_dids`
 * on purpose: the same number is often voice with one carrier and SMS
 * with another, the provisioning is a different vendor relationship,
 * and sharing one row would mean a voice DID edit silently repointing
 * text traffic to a different client.
 *
 * Endpoint → client is the FIRST routing decision on every inbound
 * message. Which queue within that client comes second, from
 * MessageQueue matching.
 *
 * An endpoint is normally identified by its carrier SENDER POOL
 * (`sender_pool_id`) — Twilio's Messaging Service and its equivalents —
 * rather than by a single number. That is deliberate and it is what
 * makes the channel compliant: the pool is where STOP/HELP enforcement,
 * sticky sender, and A2P campaign registration live. It also means a
 * client adding a number to their pool needs no change here, which is
 * the difference between a number list that is right and one that is
 * merely current.
 *
 * `address` is kept for transports with no pool concept (SMPP, WCTP
 * paging, the dev Log driver) and, where a pool is in use, as an
 * optional human label for the list.
 */
class MessagingEndpoint extends Model
{
    use BelongsToTeam;
    use HasFactory;

    protected $fillable = [
        'team_id',
        'address',
        'protocol',
        'provider',
        'sender_pool_id',
        'provider_config',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Routinely holds messaging-service SIDs and per-number
            // webhook secrets.
            'provider_config' => 'encrypted:array',
        ];
    }

    public function threads(): HasMany
    {
        return $this->hasMany(MessageThread::class);
    }

    public function optOuts(): HasMany
    {
        return $this->hasMany(MessagingOptOut::class);
    }

    /**
     * Options handed to the provider on send.
     *
     * The pool id is merged in under a fixed key rather than being left
     * to `provider_config`, so it is impossible to have an endpoint the
     * admin UI shows as pooled and the driver sees as bare.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(): array
    {
        return array_merge(
            (array) ($this->provider_config ?? []),
            ['sender_pool_id' => $this->sender_pool_id],
        );
    }

    /**
     * What this endpoint sends from, for display.
     */
    public function displayAddress(): string
    {
        return $this->address
            ?: ($this->label ?: ($this->sender_pool_id ? 'Pool '.$this->sender_pool_id : 'Unknown'));
    }

    /**
     * Find the endpoint a message arrived on by its sender pool.
     *
     * Preferred over address matching wherever the carrier reports a
     * pool, because it is exact — no formatting variants, no country
     * code guessing, and no dependence on us knowing every number the
     * client has put in their pool.
     */
    public static function resolveByPool(string $provider, string $senderPoolId): ?self
    {
        $senderPoolId = trim($senderPoolId);

        if ($senderPoolId === '') {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('provider', $provider)
            ->where('sender_pool_id', $senderPoolId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find the endpoint an inbound message arrived on.
     *
     * Providers are not consistent about E.164 formatting — the same
     * Twilio account hands back `+15551234567` on one webhook and
     * `15551234567` on another, a US number sometimes arrives without
     * its country code, and shortcodes arrive bare. Losing a client's
     * message to a plus sign is not an acceptable failure mode, so we
     * try the address as given plus a small, bounded set of equivalent
     * spellings in a single indexed query.
     *
     * Bounded on purpose: normalising every stored address in SQL would
     * mean a full table scan per inbound message, and the equivalences
     * below cover what carriers actually send.
     */
    public static function resolve(string $address, string $protocol): ?self
    {
        if (trim($address) === '') {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('protocol', $protocol)
            ->where('is_active', true)
            ->whereIn('address', self::addressVariants($address))
            ->orderByRaw('CASE WHEN address = ? THEN 0 ELSE 1 END', [$address])
            ->first();
    }

    /**
     * Equivalent spellings of one address.
     *
     * @return array<int, string>
     */
    public static function addressVariants(string $address): array
    {
        $address = trim($address);
        $digits = preg_replace('/\D+/', '', $address) ?? '';

        $variants = [$address];

        if ($digits !== '') {
            $variants[] = $digits;
            $variants[] = '+'.$digits;

            // North American numbers with and without the country code.
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $variants[] = substr($digits, 1);
                $variants[] = '+'.substr($digits, 1);
            } elseif (strlen($digits) === 10) {
                $variants[] = '1'.$digits;
                $variants[] = '+1'.$digits;
            }
        }

        return array_values(array_unique($variants));
    }
}

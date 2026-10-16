<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Telephony\ClientDidRouting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A DID (phone number) provisioned through one of the platform's SIP
 * trunks and assigned to a client. A client can carry multiple DIDs
 * across different carriers / trunks. Listings sort by `number`.
 *
 * These are platform-owned numbers, NOT customer-brought-along ones.
 */
class ClientDid extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'sip_trunk_id',
        'destination_type',
        'destination_id',
        'number',
        'label',
        'is_active',
        'notes',
    ];

    protected static function booted(): void
    {
        static::saved(fn (ClientDid $did) => app(ClientDidRouting::class)->sync($did));
        static::deleted(fn (ClientDid $did) => app(ClientDidRouting::class)->remove($did));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sipTrunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's standing instruction not to be texted by one client.
 *
 * Scope is the CLIENT, not the number they happened to text. A person
 * who says STOP has told the business to stop, and honouring that only
 * on the one line they used is the reading that produces complaints.
 *
 * A live suppression is a row with `opted_in_at` null. START/UNSTOP
 * stamps that column rather than deleting the row, because "did this
 * person ever ask us to stop, and when" is precisely the question asked
 * after the fact, and a deleted row cannot answer it.
 */
class MessagingOptOut extends Model
{
    use BelongsToTeam;
    use HasFactory;

    /**
     * Keywords that stop traffic.
     *
     * The CTIA-mandated set plus the ones carriers honour in practice.
     * Matched against the WHOLE message, never as a substring — someone
     * writing "stop by the office at four" is making an appointment,
     * not revoking consent, and treating it as a revocation silently
     * cuts a real customer off.
     */
    public const STOP_KEYWORDS = [
        'stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'optout', 'opt-out', 'revoke',
    ];

    /** Keywords that resume traffic. */
    public const START_KEYWORDS = [
        'start', 'unstop', 'yes', 'optin', 'opt-in',
    ];

    /**
     * Keywords that ask who we are. Not a suppression — but also not
     * something to hand to an LLM, since the carrier answers it with
     * the registered campaign text and a second reply from us would be
     * both redundant and, in the case of the required "reply STOP to
     * opt out" line, contradictory.
     */
    public const HELP_KEYWORDS = [
        'help', 'info',
    ];

    public const SOURCE_INBOUND = 'inbound';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_PROVIDER = 'provider';

    protected $fillable = [
        'team_id',
        'messaging_endpoint_id',
        'address',
        'address_key',
        'keyword',
        'source',
        'opted_out_at',
        'opted_in_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opted_out_at' => 'datetime',
            'opted_in_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(MessagingEndpoint::class, 'messaging_endpoint_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('opted_in_at');
    }

    public function isActive(): bool
    {
        return $this->opted_in_at === null;
    }

    /**
     * Normalise an address into the form we match on.
     *
     * Carriers are inconsistent about the leading `+` and the country
     * code, so a suppression stored one way must still match the same
     * handset spelled another. Non-numeric addresses (alphanumeric
     * sender IDs, pager identifiers) are lowercased and otherwise left
     * alone.
     *
     * This is only ever compared against itself, so the NANP assumption
     * below costs nothing when it is wrong — a ten-digit international
     * number keys consistently either way. Lookups additionally sweep
     * MessagingEndpoint::addressVariants(), which closes the gap where
     * two spellings would otherwise key differently.
     */
    public static function key(string $address): string
    {
        $address = trim($address);
        $digits = preg_replace('/\D+/', '', $address) ?? '';

        if ($digits === '') {
            return mb_strtolower($address);
        }

        // Bare North American number — the one case where a missing
        // country code is unambiguous enough to add.
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * Every key an address might have been stored under.
     *
     * @return array<int, string>
     */
    public static function keyVariants(string $address): array
    {
        $keys = array_map(
            static fn (string $variant): string => self::key($variant),
            MessagingEndpoint::addressVariants($address),
        );

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Classify a message body as an opt-out, opt-in, help request, or
     * ordinary conversation.
     *
     * Whole-message match after stripping punctuation and collapsing
     * whitespace, which is what carriers do and what the CTIA guidance
     * describes. Anything longer than the keyword is a sentence, and a
     * sentence is a conversation.
     *
     * @return array{intent: string, keyword: ?string}
     */
    public static function classify(?string $body): array
    {
        $normalised = mb_strtolower(trim((string) $body));
        $normalised = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $normalised) ?? '';
        $normalised = trim(preg_replace('/\s+/', ' ', $normalised) ?? '');

        if ($normalised === '') {
            return ['intent' => 'none', 'keyword' => null];
        }

        if (in_array($normalised, self::STOP_KEYWORDS, true)) {
            return ['intent' => 'stop', 'keyword' => $normalised];
        }

        if (in_array($normalised, self::START_KEYWORDS, true)) {
            return ['intent' => 'start', 'keyword' => $normalised];
        }

        if (in_array($normalised, self::HELP_KEYWORDS, true)) {
            return ['intent' => 'help', 'keyword' => $normalised];
        }

        return ['intent' => 'none', 'keyword' => null];
    }
}

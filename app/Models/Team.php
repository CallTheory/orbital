<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'timezone',
        'account_number',
        'personal_team',
        'max_users',
        'max_concurrent_calls',
        'suspended_at',
        'recording_overrides',
        'tier',
        'keep_partial_messages',
        'two_factor_grace_days',
    ];

    /** Service tier vocabulary used by QueueMemberSyncer's penalty math. */
    public const TIERS = ['free', 'pro', 'enterprise'];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'suspended_at' => 'datetime',
            'account_number' => 'integer',
            'max_users' => 'integer',
            'max_concurrent_calls' => 'integer',
            'recording_overrides' => 'array',
            // Keep a half-collected message when the caller hangs up
            // mid-intake. See App\Services\Messages\PartialMessagePolicy.
            'keep_partial_messages' => 'boolean',
            'two_factor_grace_days' => 'integer',
            // Voicemail transcription creds — API keys live per-client,
            // encrypted at rest. The cast handles encrypt/decrypt on
            // read and write so consumers just see a plain array.
            'voicemail_transcription_config' => 'encrypted:array',
        ];
    }

    public function sipTrunks(): HasMany
    {
        return $this->hasMany(SipTrunk::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    public function agentPersonas(): HasMany
    {
        return $this->hasMany(AgentPersona::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ClientSlot::class)->orderBy('name');
    }

    public function intakeFlows(): HasMany
    {
        return $this->hasMany(IntakeFlow::class);
    }

    public function orchestrations(): HasMany
    {
        return $this->hasMany(Orchestration::class);
    }

    public function knowledgeStores(): HasMany
    {
        return $this->hasMany(KnowledgeStore::class);
    }

    public function callQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class);
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }

    public function emailRoutingRules(): HasMany
    {
        return $this->hasMany(EmailRoutingRule::class);
    }

    public function emailQueues(): HasMany
    {
        return $this->hasMany(EmailQueue::class);
    }

    public function messageQueues(): HasMany
    {
        return $this->hasMany(MessageQueue::class);
    }

    /**
     * Numbers and shortcodes this client receives text traffic on.
     *
     * Separate from dids(): the same number is routinely voice with one
     * carrier and SMS with another, and sharing one row would mean a
     * voice DID edit silently repointing text traffic.
     */
    public function messagingEndpoints(): HasMany
    {
        // Ordered by pool rather than address: with a carrier sender
        // pool required, `address` is an optional display label and is
        // frequently null, which would sort the list arbitrarily.
        return $this->hasMany(MessagingEndpoint::class)->orderBy('sender_pool_id')->orderBy('address');
    }

    /**
     * People who have told this client to stop texting them.
     */
    public function messagingOptOuts(): HasMany
    {
        return $this->hasMany(MessagingOptOut::class)->orderByDesc('opted_out_at');
    }

    public function messageThreads(): HasMany
    {
        return $this->hasMany(MessageThread::class);
    }

    public function chatQueues(): HasMany
    {
        return $this->hasMany(ChatQueue::class);
    }

    public function emailThreads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function dids(): HasMany
    {
        return $this->hasMany(ClientDid::class)->orderBy('number');
    }

    /**
     * One representative active DID for column displays. With per-DID
     * failover priority gone, this is just "first active DID by
     * number" — fine for the common case where a client only has one
     * or two DIDs and we just want a number to show in a list cell.
     */
    public function primaryDid(): ?ClientDid
    {
        return $this->dids()
            ->where('is_active', true)
            ->first();
    }

    /**
     * Client's own phone book, used by AI agents and live operators
     * while handling a call. Distinct from `team_user` pivot, which
     * holds login accounts. Directory is arbitrary contacts with
     * client-defined custom fields.
     */
    public function directoryEntries(): HasMany
    {
        return $this->hasMany(DirectoryEntry::class);
    }

    /**
     * Client-authored Directory field schema. Defines the input
     * schema captured into each DirectoryEntry's `values` JSONB.
     */
    public function directoryFieldDefinitions(): HasMany
    {
        return $this->hasMany(DirectoryFieldDefinition::class)->orderBy('sort_order');
    }

    /**
     * Platform-level shared directories this client is
     * subscribed to. Managed by super-admins; clients see shared
     * rows unioned into their directory view.
     */
    public function sharedDirectories(): BelongsToMany
    {
        return $this->belongsToMany(SharedDirectory::class, 'team_shared_directory')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * The timezone the client operates in. Falls back to app
     * default when not explicitly set.
     */
    public function displayTimezone(): string
    {
        return ! empty($this->timezone)
            ? $this->timezone
            : (string) config('app.timezone');
    }

    /**
     * The canonical Asterisk dialplan context for this client. Each
     * client's routing logic lives inside `[tenant_{id}]` so DIDs
     * dispatched from `[from-trunk]` Goto into the right namespace
     * and queue / extension references can't collide with another
     * client's dialplan.
     */
    public function dialplanContext(): string
    {
        return 'tenant_'.$this->id;
    }
}

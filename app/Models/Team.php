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

    public function intakeGoals(): HasMany
    {
        return $this->hasMany(IntakeGoal::class);
    }

    public function intakeFlows(): HasMany
    {
        return $this->hasMany(IntakeFlow::class);
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

    public function tenantDids(): HasMany
    {
        return $this->hasMany(TenantDid::class)->orderBy('priority');
    }

    /**
     * The active DID with the highest priority (lowest priority number).
     */
    public function primaryDid(): ?TenantDid
    {
        return $this->tenantDids()
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();
    }

    /**
     * Account-level tenant contacts — people we communicate with about
     * the tenant's account (billing, holiday, newsletter, escalation).
     * Most contacts never log in; the ones that do have `user_id` set.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Tenant's own phone book, used by AI agents and live operators
     * while handling a call. Separate from `contacts` — different use
     * case, different vocabulary, different table.
     */
    public function directoryEntries(): HasMany
    {
        return $this->hasMany(DirectoryEntry::class);
    }

    /**
     * Tenant-authored Contact field schema. Each row defines one
     * input on the Contacts form for this tenant only — label,
     * type, sort order, and an optional semantic role (name / email
     * / phone / organization). The values captured by these
     * definitions live in the `contacts.values` JSONB column.
     */
    public function contactFieldDefinitions(): HasMany
    {
        return $this->hasMany(ContactFieldDefinition::class)->orderBy('sort_order');
    }

    /**
     * Tenant-authored Directory field schema. Parallel to contact
     * fields — separate table, separate vocabulary.
     */
    public function directoryFieldDefinitions(): HasMany
    {
        return $this->hasMany(DirectoryFieldDefinition::class)->orderBy('sort_order');
    }

    /**
     * Platform-level shared contact lists this tenant is
     * subscribed to. Managed by super-admins under
     * `/admin/shared-contact-lists`. The `is_active` pivot flag
     * lets a super-admin temporarily detach a list without
     * destroying the link. The Contact global scope checks
     * this pivot via subquery to union shared rows into
     * tenant-scoped queries.
     */
    public function sharedContactLists(): BelongsToMany
    {
        return $this->belongsToMany(SharedContactList::class, 'team_shared_contact_list')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Platform-level shared directories this tenant is
     * subscribed to. Same pattern as sharedContactLists but
     * targeting the "phone book used during call handling"
     * side of the split.
     */
    public function sharedDirectories(): BelongsToMany
    {
        return $this->belongsToMany(SharedDirectory::class, 'team_shared_directory')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * The timezone the tenant operates in. Falls back to app
     * default when not explicitly set.
     */
    public function displayTimezone(): string
    {
        return ! empty($this->timezone)
            ? $this->timezone
            : (string) config('app.timezone');
    }

    /**
     * The canonical Asterisk dialplan context for this tenant. Each
     * tenant's routing logic lives inside `[tenant_{id}]` so DIDs
     * dispatched from `[from-trunk]` Goto into the right namespace
     * and queue / extension references can't collide with another
     * tenant's dialplan.
     */
    public function dialplanContext(): string
    {
        return 'tenant_'.$this->id;
    }
}

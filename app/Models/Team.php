<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'account_number',
        'personal_team',
        'max_users',
        'max_concurrent_calls',
        'suspended_at',
        'recording_overrides',
    ];

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

    public function sipTrunks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SipTrunk::class);
    }

    public function extensions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Extension::class);
    }

    public function agentPersonas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgentPersona::class);
    }

    public function intakeGoals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IntakeGoal::class);
    }

    public function intakeFlows(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IntakeFlow::class);
    }

    public function knowledgeStores(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KnowledgeStore::class);
    }

    public function callQueues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CallQueue::class);
    }

    public function routingRules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }

    public function callLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function operatingHours(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function tenantDids(): \Illuminate\Database\Eloquent\Relations\HasMany
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
     * Tenant contacts (users with the tenant_user role inside this team).
     * Uses Jetstream's users() relationship under the hood.
     */
    public function contacts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->users();
    }
}

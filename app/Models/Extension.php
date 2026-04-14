<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Extension extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'number',
        'type',
        'label',
        'assignable_type',
        'assignable_id',
        'sip_username',
        'sip_password',
        'transport',
        'context',
        'mailbox',
        'intake_flow_id',
        'recording_mode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sip_password' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * Optional per-extension override of the assigned persona's default flow.
     */
    public function intakeFlow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'intake_flow_id');
    }

    /**
     * Agent group memberships — polymorphic. A hardware extension can be
     * a member of one or more platform agent groups.
     */
    public function agentGroupMemberships(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(AgentGroupMember::class, 'member');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Services\Clients\TemplateResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentPersona extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'template_id',
        'name',
        'role',
        'description',
        'avatar_path',
        'system_prompt',
        'greeting',
        'outbound_greeting',
        'personality',
        'voice_id',
        'voice_config',
        'llm_provider',
        'llm_model',
        'stt_provider',
        'tts_provider',
        'tools_config',
        'default_flow_id',
        'overrides',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'voice_config' => 'array',
            'tools_config' => 'array',
            'overrides' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The template this persona is linked to (null = standalone or template itself).
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(self::class, 'template_id');
    }

    /**
     * Client instances linked to this template.
     */
    public function instances(): HasMany
    {
        return $this->hasMany(self::class, 'template_id');
    }

    /**
     * Is this record a platform-library template?
     */
    public function isTemplate(): bool
    {
        return $this->team_id === null && $this->template_id === null;
    }

    /**
     * Return all effective attribute values (template + overrides merged).
     *
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        return app(TemplateResolver::class)->effective($this);
    }

    /**
     * Convenience: resolve a single field through the template.
     */
    public function effectiveField(string $field): mixed
    {
        return app(TemplateResolver::class)->resolve($this, $field);
    }

    public function extensions(): MorphMany
    {
        return $this->morphMany(Extension::class, 'assignable');
    }

    /**
     * The intake flow this persona falls back to when no extension-level
     * or routing-rule-level override applies.
     */
    public function defaultFlow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'default_flow_id');
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function overflowQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class, 'overflow_agent_persona_id');
    }
}

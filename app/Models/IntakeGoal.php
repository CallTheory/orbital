<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Services\Tenancy\TemplateResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single structured objective inside a call flow.
 *
 * Platform library rows (team_id = null, template_id = null) form the
 * authoritative vocabulary. Tenants can clone them into instances that
 * point at the template and store only their field-level overrides,
 * same pattern as AgentPersona.
 *
 * Intake goals are read by three compilers (AI voice, operator UI,
 * chat) that each render the same structured fields — talking_points,
 * data_fields, completion, tools — into their native form.
 */
class IntakeGoal extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'template_id',
        'key',
        'name',
        'description',
        'category',
        'icon',
        'talking_points',
        'data_fields',
        'completion',
        'tools',
        'knowledge_store_ids',
        'voice_overrides',
        'operator_overrides',
        'chat_overrides',
        'is_active',
        'overrides',
    ];

    protected function casts(): array
    {
        return [
            'talking_points' => 'array',
            'data_fields' => 'array',
            'completion' => 'array',
            'tools' => 'array',
            'knowledge_store_ids' => 'array',
            'voice_overrides' => 'array',
            'operator_overrides' => 'array',
            'chat_overrides' => 'array',
            'overrides' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Template this goal instance is linked to (null = standalone or template itself).
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(self::class, 'template_id');
    }

    /**
     * Tenant instances linked to this template.
     */
    public function instances(): HasMany
    {
        return $this->hasMany(self::class, 'template_id');
    }

    /**
     * Is this row a platform-library template?
     */
    public function isTemplate(): bool
    {
        return $this->team_id === null && $this->template_id === null;
    }

    /**
     * Return all effective attribute values with template + overrides merged.
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
}

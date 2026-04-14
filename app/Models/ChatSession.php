<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A public-facing chat session. Anonymous by design — the customer
 * doesn't have a Laravel user, just an unguessable URL. Operators can
 * still read transcripts through the admin panel because each row
 * records its team_id.
 */
class ChatSession extends Model
{
    protected $fillable = [
        'public_token',
        'team_id',
        'agent_persona_id',
        'messages',
        'fields',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'fields' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if (empty($session->public_token)) {
                $session->public_token = Str::random(40);
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'agent_persona_id');
    }

    /**
     * Append one message to the transcript.
     *
     * @param  'user'|'assistant'|'system'  $role
     */
    public function appendMessage(string $role, string $content): void
    {
        $messages = $this->messages ?? [];
        $messages[] = [
            'role' => $role,
            'content' => $content,
            'ts' => now()->toIso8601String(),
        ];
        $this->messages = $messages;
        $this->last_activity_at = now();
        $this->save();
    }
}

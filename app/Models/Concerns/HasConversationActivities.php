<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ConversationActivity;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add to any conversation-like model (EmailThread, ChatSession,
 * CallLog, etc.) to give it a polymorphic activity timeline.
 */
trait HasConversationActivities
{
    public function activities(): MorphMany
    {
        return $this->morphMany(ConversationActivity::class, 'subject')
            ->orderBy('created_at');
    }
}

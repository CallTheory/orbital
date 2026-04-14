<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ChatSession;
use App\Services\Chat\ChatService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public-facing chat widget. Renders the running conversation for a
 * single ChatSession and hands new turns off to ChatService (which
 * talks to Anthropic via the compiled flow instructions).
 *
 * No auth — anyone who knows the session's public token (via the
 * route) can participate. That's the point of a public chat URL.
 */
class ChatConsole extends Component
{
    #[Locked]
    public int $sessionId;

    /** @var array<int, array{role: string, content: string, ts: ?string}> */
    public array $messages = [];

    public string $draft = '';

    public bool $pending = false;

    public ?string $errorMessage = null;

    public function mount(int $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->reloadMessages();
    }

    public function send(ChatService $chat): void
    {
        $text = trim($this->draft);
        if ($text === '' || $this->pending) {
            return;
        }

        $this->pending = true;
        $this->errorMessage = null;
        $this->draft = '';

        $session = ChatSession::findOrFail($this->sessionId);

        try {
            $chat->handleTurn($session, $text);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }

        $this->reloadMessages();
        $this->pending = false;
    }

    public function render()
    {
        return view('livewire.chat-console');
    }

    private function reloadMessages(): void
    {
        $session = ChatSession::find($this->sessionId);
        $this->messages = $session?->messages ?? [];
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ConversationActivity;
use App\Models\EmailThread;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Standalone Filament table for conversation activity entries.
 *
 * Embedded as a Livewire component on the ViewEmailThread page
 * so the messages table and activity table are both proper
 * Filament tables (Filament only supports one table per page
 * via InteractsWithTable, so activity gets its own component).
 */
class ThreadActivityTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public int $threadId;

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function table(Table $table): Table
    {
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');

        return $table
            ->query(
                ConversationActivity::query()
                    ->where('subject_type', (new EmailThread)->getMorphClass())
                    ->where('subject_id', $this->threadId)
                    ->with('user')
                    ->latest('created_at'),
            )
            ->heading('Activity')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state, ConversationActivity $record): string => match ($state) {
                        'replied' => 'Replied to '.collect($record->metadata['to'] ?? [])->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', '),
                        'forwarded' => 'Forwarded to '.($record->metadata['to'] ?? 'unknown'),
                        'message_taken' => 'Took a message',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('g:i a')
                    ->timezone($tz)
                    ->tooltip(fn (ConversationActivity $record) => $record->created_at?->timezone($tz)->format('M j, Y g:i:s a T')),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('System'),
            ]);
    }

    public function render(): View
    {
        return view('livewire.thread-activity-table');
    }
}

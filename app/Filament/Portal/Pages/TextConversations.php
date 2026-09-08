<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Models\MessageThread;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Client-scoped view of the text conversations handled on their behalf.
 *
 * Read-only, like the rest of the portal: the client sees what was said
 * to their customers, not a surface to reply from. Replying is the
 * answering service's job — that's what they're paying for, and a client
 * texting from the same number mid-conversation would collide with the
 * operator working it.
 *
 * Scoped explicitly on `current_team_id` rather than relying on
 * BelongsToTeam's global scope, for the same reason CallHistory does:
 * portal auth context doesn't share state with the admin panel's team
 * scope, and "filter explicitly" beats "hope the global scope is
 * configured right" on a page a client user can open.
 */
class TextConversations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Text Messages';

    protected static ?string $title = 'Text Messages';

    protected static ?int $navigationSort = -7;

    protected static ?string $slug = 'texts';

    protected string $view = 'filament.portal.pages.text-conversations';

    /**
     * Hidden entirely for clients who don't have the channel. An empty
     * nav item on a product they don't use reads as something broken.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $teamId = (int) (auth()->user()?->current_team_id ?? 0);

        return $teamId > 0 && MessageThread::query()->where('team_id', $teamId)->exists();
    }

    public function table(Table $table): Table
    {
        $teamId = (int) (auth()->user()->current_team_id ?? 0);

        return $table
            ->query(
                MessageThread::query()
                    ->where('team_id', $teamId)
                    ->with(['entries', 'endpoint'])
                    ->orderByDesc('last_message_at'),
            )
            ->filters([
                TernaryFilter::make('closed')
                    ->label('Include closed')
                    ->placeholder('Open conversations only')
                    ->trueLabel('Include closed')
                    ->falseLabel('Closed only')
                    ->queries(
                        true: fn ($query) => $query,
                        false: fn ($query) => $query->where('status', MessageThread::STATUS_CLOSED),
                        blank: fn ($query) => $query->where('status', '!=', MessageThread::STATUS_CLOSED),
                    ),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Last activity')
                    ->dateTime('M j, Y g:i a')
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('remote_address')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (MessageThread $record) => $record->preview(60)),

                Tables\Columns\TextColumn::make('endpoint.address')
                    ->label('Your number')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('protocol')
                    ->label('Via')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),

                Tables\Columns\TextColumn::make('entries_count')
                    ->label('Messages')
                    ->counts('entries'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        MessageThread::STATUS_NEW => 'info',
                        MessageThread::STATUS_IN_PROGRESS => 'primary',
                        MessageThread::STATUS_AWAITING_REPLY => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->emptyStateHeading('No text conversations')
            ->emptyStateDescription('Texts to your messaging numbers, and the replies sent on your behalf, appear here.');
    }
}

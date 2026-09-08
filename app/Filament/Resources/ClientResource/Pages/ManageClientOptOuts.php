<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\MessagingOptOut;
use App\Services\Messaging\OptOutRegistry;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The client's do-not-text list.
 *
 * Most rows arrive on their own: a customer texts STOP, the inbound job
 * records it, and nobody here does anything. This page exists for the
 * cases that don't come in over the wire — someone tells an operator on
 * a call, or writes in, or the client forwards a complaint — and for
 * the question that gets asked afterwards, which is always "when did
 * they ask us, and how do we know".
 *
 * Removing consent is a one-click action. RESTORING it is not, and is
 * not offered as a bulk operation, because "we opted them back in" is
 * the sentence at the centre of every text-messaging complaint. A
 * customer opts themselves back in by texting START; an operator can do
 * it individually, with their name recorded against it.
 */
class ManageClientOptOuts extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'messagingOptOuts';

    protected static ?string $modelLabel = 'opt-out';

    protected static ?string $pluralModelLabel = 'opt-outs';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationLabel = 'Do Not Text';

    protected static ?string $title = 'Do Not Text';

    public static function getNavigationLabel(): string
    {
        return 'Do Not Text';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('address')
                ->label('Number')
                ->placeholder('+15551234567')
                ->required()
                ->maxLength(64)
                ->helperText('The handset that asked not to be texted. Formatting is normalised, so "+1 555 123 4567" and "5551234567" are the same person.'),

            Forms\Components\Textarea::make('keyword')
                ->label('How they asked')
                ->rows(2)
                ->maxLength(32)
                ->placeholder('Told the operator on a call')
                ->helperText('Recorded for the audit trail. Short — this is a note, not a case file.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('address')
            ->columns([
                Tables\Columns\TextColumn::make('address')
                    ->label('Number')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (MessagingOptOut $record): ?string => $record->address_key !== $record->address
                        ? $record->address_key
                        : null),

                Tables\Columns\TextColumn::make('opted_out_at')
                    ->label('Asked us to stop')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('keyword')
                    ->label('How')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color(fn (string $state): string => $state === MessagingOptOut::SOURCE_INBOUND ? 'gray' : 'warning'),

                Tables\Columns\TextColumn::make('createdByUser.name')
                    ->label('Recorded by')
                    ->placeholder('Customer'),

                Tables\Columns\TextColumn::make('opted_in_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Opted back in' : 'Do not text')
                    ->color(fn ($state): string => $state ? 'success' : 'danger'),
            ])
            ->defaultSort('opted_out_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Currently suppressed')
                    ->placeholder('All')
                    ->trueLabel('Do not text')
                    ->falseLabel('Opted back in')
                    ->queries(
                        true: fn ($query) => $query->whereNull('opted_in_at'),
                        false: fn ($query) => $query->whereNotNull('opted_in_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add number')
                    ->using(function (array $data): MessagingOptOut {
                        // Through the registry rather than a plain
                        // create(), so a manual entry keys the address
                        // exactly the way an inbound STOP would. Two
                        // spellings of one handset is the same as no
                        // suppression at all.
                        return app(OptOutRegistry::class)->optOut(
                            teamId: $this->getOwnerRecord()->getKey(),
                            address: (string) $data['address'],
                            keyword: $data['keyword'] ?? null,
                            source: MessagingOptOut::SOURCE_ADMIN,
                            actor: auth()->user(),
                        );
                    }),
            ])
            ->actions([
                Actions\Action::make('optIn')
                    ->label('Opt back in')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (MessagingOptOut $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Allow texts to this number again?')
                    ->modalDescription('Only do this if the customer has asked for it. Their request to stop is on record, and resuming without one is the complaint everybody remembers. Normally the customer opts themselves back in by texting START.')
                    ->modalSubmitActionLabel('They asked to resume')
                    ->action(function (MessagingOptOut $record): void {
                        app(OptOutRegistry::class)->optIn(
                            teamId: $record->team_id,
                            address: $record->address,
                            actor: auth()->user(),
                        );

                        Notification::make()
                            ->title('Texts allowed again')
                            ->body('Recorded against your name.')
                            ->success()
                            ->send();
                    }),
            ])
            // No bulk delete. Removing suppressions a hundred at a time
            // is not an operation this list should make easy.
            ->bulkActions([])
            ->emptyStateHeading('Nobody has opted out')
            ->emptyStateDescription('Customers who text STOP are added here automatically and stop receiving messages immediately. Add one by hand when somebody asks an operator instead.');
    }
}

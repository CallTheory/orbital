<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\Orchestration;
use App\Models\SipTrunk;
use App\Services\Telephony\ClientDidRouting;
use BackedEnum;
use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ManageClientDids extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'dids';

    protected static ?string $modelLabel = 'DID';

    protected static ?string $pluralModelLabel = 'DIDs';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'DIDs';

    protected static ?string $title = 'Phone Numbers (DIDs)';

    public static function getNavigationLabel(): string
    {
        return 'DIDs';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->label('Phone Number')
                    ->placeholder('+15551234567')
                    ->required()
                    ->maxLength(32)
                    ->unique(ignoreRecord: true)
                    ->helperText('Use E.164 format (e.g. +15551234567).'),
                Forms\Components\TextInput::make('label')
                    ->maxLength(255)
                    ->placeholder('Primary, Backup, etc.'),
                Forms\Components\Select::make('sip_trunk_id')
                    ->label('Provisioned via Trunk')
                    ->options(fn () => SipTrunk::withoutGlobalScope('team')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Which carrier trunk delivers calls to this DID.'),
                Forms\Components\Select::make('destination_type')
                    ->label('Send calls to')
                    ->options([
                        'extension' => 'Extension (person or AI agent)',
                        'queue' => 'Call queue',
                        'voicemail' => 'Voicemail box',
                        ClientDidRouting::DESTINATION_ORCHESTRATION => 'Orchestration (Inbound Phone)',
                    ])
                    ->placeholder('Nowhere yet (calls are hung up)')
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('destination_id', null)),
                Forms\Components\Select::make('destination_id')
                    ->label(fn (Get $get): string => match ($get('destination_type')) {
                        'queue' => 'Queue',
                        ClientDidRouting::DESTINATION_ORCHESTRATION => 'Orchestration',
                        default => 'Extension',
                    })
                    ->options(fn (Get $get): array => $this->destinationOptions($get('destination_type')))
                    ->searchable()
                    ->helperText(fn (Get $get): ?string => $get('destination_type') === ClientDidRouting::DESTINATION_ORCHESTRATION
                        ? 'Calls start at the orchestration\'s Inbound Phone channel and are answered by the persona chosen in that channel\'s settings.'
                        : null)
                    ->rules(fn (Get $get): array => $get('destination_type') === ClientDidRouting::DESTINATION_ORCHESTRATION
                        ? [fn (string $attribute, mixed $value, Closure $fail) => $this->checkInboundPhoneChannel((int) $value, $fail)]
                        : [])
                    ->visible(fn (Get $get): bool => filled($get('destination_type')))
                    ->required(fn (Get $get): bool => filled($get('destination_type'))),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
                Forms\Components\Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        // Click the number cell to open the standard edit modal —
        // no inline action buttons cluttering each row. Bulk select
        // handles delete.
        $editAction = Actions\EditAction::make();

        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Number')
                    ->searchable()
                    ->weight('medium')
                    ->action($editAction),
                Tables\Columns\TextColumn::make('label')
                    ->placeholder('—')
                    ->action($editAction),
                Tables\Columns\TextColumn::make('sipTrunk.name')
                    ->label('Trunk')
                    ->placeholder('Any')
                    ->sortable(),
                Tables\Columns\TextColumn::make('destination_type')
                    ->label('Routes to')
                    ->formatStateUsing(fn (?string $state, $record): string => $state
                        ? ($this->destinationOptions($state)[$record->destination_id] ?? ucfirst($state).' '.$record->destination_id)
                        : '—')
                    ->placeholder('Not routed'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('number')
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Destinations a DID can route to, keyed by RoutingRule
     * destination_id: the extension number (also the voicemail mailbox)
     * or the queue id.
     *
     * @return array<int|string, string>
     */
    protected function destinationOptions(?string $type): array
    {
        $teamId = $this->getOwnerRecord()->getKey();

        return match ($type) {
            'extension', 'voicemail' => Extension::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('number')
                ->get()
                ->filter(fn (Extension $ext) => ctype_digit((string) $ext->number))
                ->filter(fn (Extension $ext) => $type === 'extension' || $ext->type !== 'ai_agent')
                ->mapWithKeys(fn (Extension $ext) => [
                    (int) $ext->number => $ext->number.' — '.($ext->label ?: str_replace('_', ' ', (string) $ext->type)),
                ])
                ->all(),
            'queue' => CallQueue::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            ClientDidRouting::DESTINATION_ORCHESTRATION => Orchestration::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            default => [],
        };
    }

    /**
     * A DID sent to an orchestration needs its Inbound Phone channel to
     * say which persona answers; without one the call would hang up.
     */
    protected function checkInboundPhoneChannel(int $orchestrationId, Closure $fail): void
    {
        $channel = ClientDidRouting::inboundPhoneChannel((int) $this->getOwnerRecord()->getKey(), $orchestrationId);

        if (! $channel) {
            $fail('This orchestration has no Inbound Phone channel. Open it in the editor once to add one.');
        } elseif (! $channel->agent_persona_id) {
            $fail('Choose the persona that answers in the Inbound Phone channel\'s settings first.');
        } elseif (! $channel->channelEntryFlow()) {
            $fail('Connect the Inbound Phone channel to a flow first.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\Team;
use App\Models\TeamApiKey;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Js;

/**
 * API keys the client's own systems use against the v1 API (handoff
 * lookup). The plain token is shown once, at creation.
 */
class ManageClientApiKeys extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'apiKeys';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'API Keys';

    protected static ?string $title = 'API Keys';

    public static function getNavigationLabel(): string
    {
        return 'API Keys';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('token_prefix')
                    ->label('Key')
                    ->formatStateUsing(fn (string $state): string => $state.'…')
                    ->fontFamily('mono')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('last_used_at')->dateTime()->placeholder('Never'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (TeamApiKey $record): string => $record->isRevoked() ? 'Revoked' : 'Active')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
            ])
            ->headerActions([
                Actions\Action::make('create')
                    ->label('Create API key')
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('What this key is for, e.g. "Acme live-operator script".'),
                    ])
                    ->action(function (array $data): void {
                        /** @var Team $team */
                        $team = $this->getOwnerRecord();
                        [, $token] = TeamApiKey::issue($team, $data['name']);

                        Notification::make()
                            ->title('API key created. Copy it now, it will not be shown again.')
                            ->body($token)
                            ->actions([
                                Actions\Action::make('copy')
                                    ->label('Copy key')
                                    ->alpineClickHandler('window.navigator.clipboard.writeText('.Js::from($token).')')
                                    ->close(),
                            ])
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Systems using this key will immediately get 401 errors.')
                    ->visible(fn (TeamApiKey $record): bool => ! $record->isRevoked())
                    ->action(fn (TeamApiKey $record) => $record->revoke()),
            ]);
    }
}

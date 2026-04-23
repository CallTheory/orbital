<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\AgentPersona;
use App\Models\EmailQueue;
use App\Models\EmailRoutingRule;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side page for managing a client's inbound email routing
 * rules. Mirrors `ManageClientRoutingRules` (voice) but with an
 * email-flavored enum set:
 *
 *   match_type:
 *     function        → matches `.function` suffix on local-part
 *     from_pattern    → regex on From: header
 *     subject_pattern → regex on Subject: header
 *     default         → catch-all for this client
 *
 *   destination_type:
 *     queue         → email_queues.id (Phase 3)
 *     operator      → users.id (direct assignment)
 *     agent_persona → agent_personas.id (AI handoff)
 *     discard       → drop the message
 */
class ManageClientEmailRules extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'emailRoutingRules';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $navigationLabel = 'Email Rules';

    protected static ?string $title = 'Email Rules';

    public static function getNavigationLabel(): string
    {
        return 'Email Rules';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Alarms → on-call queue'),

                Forms\Components\Select::make('match_type')
                    ->label('Match on')
                    ->options([
                        EmailRoutingRule::MATCH_FUNCTION => 'Function suffix (e.g. .alarms)',
                        EmailRoutingRule::MATCH_FROM_PATTERN => 'From address regex',
                        EmailRoutingRule::MATCH_SUBJECT_PATTERN => 'Subject regex',
                        EmailRoutingRule::MATCH_DEFAULT => 'Default (catch-all for this client)',
                    ])
                    ->default(EmailRoutingRule::MATCH_DEFAULT)
                    ->required()
                    ->live()
                    ->helperText('Function rules fire first when the recipient has a .function suffix. Default rules fire last.'),

                Forms\Components\TextInput::make('match_pattern')
                    ->label(fn (Get $get) => match ($get('match_type')) {
                        EmailRoutingRule::MATCH_FUNCTION => 'Function suffix',
                        EmailRoutingRule::MATCH_FROM_PATTERN => 'From regex',
                        EmailRoutingRule::MATCH_SUBJECT_PATTERN => 'Subject regex',
                        default => 'Match pattern',
                    })
                    ->placeholder(fn (Get $get) => match ($get('match_type')) {
                        EmailRoutingRule::MATCH_FUNCTION => 'alarms',
                        EmailRoutingRule::MATCH_FROM_PATTERN => '@important-vendor\.com$',
                        EmailRoutingRule::MATCH_SUBJECT_PATTERN => '(urgent|critical|priority)',
                        default => '(not used for default rules)',
                    })
                    ->visible(fn (Get $get) => $get('match_type') !== EmailRoutingRule::MATCH_DEFAULT)
                    ->helperText(fn (Get $get) => match ($get('match_type')) {
                        EmailRoutingRule::MATCH_FUNCTION => 'Plain string. Matches messages sent to {account}.{function}@...',
                        EmailRoutingRule::MATCH_FROM_PATTERN,
                        EmailRoutingRule::MATCH_SUBJECT_PATTERN => 'PCRE pattern. Case-insensitive. Delimiters optional.',
                        default => null,
                    }),

                Forms\Components\Select::make('destination_type')
                    ->label('Destination')
                    ->options([
                        EmailRoutingRule::DESTINATION_QUEUE => 'Email Queue',
                        EmailRoutingRule::DESTINATION_OPERATOR => 'Direct to operator',
                        EmailRoutingRule::DESTINATION_AGENT_PERSONA => 'AI agent persona',
                        EmailRoutingRule::DESTINATION_DISCARD => 'Discard (drop message)',
                    ])
                    ->default(EmailRoutingRule::DESTINATION_QUEUE)
                    ->required()
                    ->live(),

                Forms\Components\Select::make('destination_id')
                    ->label('Target')
                    ->options(function (Get $get) {
                        $type = $get('destination_type');
                        $teamId = $this->getOwnerRecord()->id;

                        return match ($type) {
                            EmailRoutingRule::DESTINATION_QUEUE => EmailQueue::query()
                                ->where('team_id', $teamId)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id'),
                            EmailRoutingRule::DESTINATION_OPERATOR => User::query()
                                ->whereExists(fn ($q) => $q
                                    ->select(DB::raw(1))
                                    ->from('model_has_roles')
                                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                                    ->whereColumn('model_has_roles.model_id', 'users.id')
                                    ->where('model_has_roles.model_type', User::class)
                                    ->whereIn('roles.name', ['operator', 'supervisor']))
                                ->pluck('name', 'id'),
                            EmailRoutingRule::DESTINATION_AGENT_PERSONA => AgentPersona::query()
                                ->where('team_id', $teamId)
                                ->pluck('name', 'id'),
                            EmailRoutingRule::DESTINATION_DISCARD => [],
                            default => [],
                        };
                    })
                    ->searchable()
                    ->placeholder(fn (Get $get) => match ($get('destination_type')) {
                        EmailRoutingRule::DESTINATION_DISCARD => 'No target needed',
                        default => 'Select…',
                    })
                    ->disabled(fn (Get $get) => $get('destination_type') === EmailRoutingRule::DESTINATION_DISCARD),

                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->default(100)
                    ->helperText('Lower = evaluated first. Default rules should be highest (e.g. 100).'),

                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('match_type')->badge(),
                Tables\Columns\TextColumn::make('match_pattern')->label('Match')->placeholder('—'),
                Tables\Columns\TextColumn::make('destination_type')->badge(),
                Tables\Columns\TextColumn::make('destination_id')->label('Target ID')->placeholder('—'),
                Tables\Columns\TextColumn::make('priority')->sortable()->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('priority')
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}

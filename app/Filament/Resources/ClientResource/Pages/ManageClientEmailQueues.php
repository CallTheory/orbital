<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\AgentGroup;
use App\Models\AgentPersona;
use App\Models\EmailQueue;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Admin-side page for managing a client's email queues.
 *
 * Mirrors `ManageClientCallQueues` but with email-shaped fields.
 * No ring timeouts, no wrapup, no music-on-hold — email queues
 * are inboxes, not ring groups. The strategy describes how
 * operators claim threads; overflow AI handles the thread
 * autonomously when no human is available.
 *
 * An `EmailRoutingRule` with `destination_type='queue'` points
 * its `destination_id` at a row here. The router stamps
 * `email_threads.email_queue_id` so threads can be filtered by
 * queue in the operator inbox.
 */
class ManageClientEmailQueues extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'emailQueues';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Email Queues';

    protected static ?string $title = 'Email Queues';

    public static function getNavigationLabel(): string
    {
        return 'Email Queues';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Support, Alarms, Billing, …'),

                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(1000)
                    ->placeholder('Optional note for operators about what kind of threads this queue holds.'),

                Forms\Components\Select::make('strategy')
                    ->label('Claim strategy')
                    ->options([
                        EmailQueue::STRATEGY_MANUAL => 'Manual — operators pull threads freely',
                        EmailQueue::STRATEGY_ROUND_ROBIN => 'Round robin — next thread → next operator in order',
                        EmailQueue::STRATEGY_LONGEST_IDLE => 'Longest idle — next thread → operator who hasn\'t worked recently',
                        EmailQueue::STRATEGY_AI_FIRST => 'AI first — overflow persona takes everything unless escalated',
                    ])
                    ->default(EmailQueue::STRATEGY_MANUAL)
                    ->required()
                    ->helperText('Phase 3 ships with "manual" wired up; auto-assignment strategies land in a polish pass.'),

                Forms\Components\Select::make('agent_group_id')
                    ->label('Operator group')
                    ->options(fn () => AgentGroup::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Open — all operators')
                    ->helperText('Only operators in this group will see threads in this queue. Leave empty for all operators.'),

                Forms\Components\Select::make('overflow_agent_persona_id')
                    ->label('Overflow AI persona')
                    ->options(fn () => AgentPersona::query()
                        ->where('team_id', $this->getOwnerRecord()->id)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('None — threads wait for a human')
                    ->helperText('Optional. When nobody picks up, or when strategy=ai_first, the thread hands off to this persona via ProcessEmailWithAgentJob.'),

                Forms\Components\Toggle::make('is_active')->default(true),

                Forms\Components\TagsInput::make('matched_addresses')
                    ->label('Matched addresses / local-part patterns')
                    ->placeholder('e.g. support, billing, alarms')
                    ->helperText('Inbound emails with these local-parts (or full addresses) route to this queue. Patterns are matched against the envelope / To header. Leave empty to make this queue invisible to inbound mail.'),

                Forms\Components\TextInput::make('matched_domain')
                    ->label('Matched domain (optional)')
                    ->maxLength(255)
                    ->placeholder('e.g. acme.orbital.example')
                    ->helperText('Scope matches to this domain only. Leave empty to match any tenant domain this client owns.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('strategy')
                    ->badge(),
                Tables\Columns\TextColumn::make('agentGroup.name')
                    ->label('Operator group')
                    ->placeholder('Open — all'),
                Tables\Columns\TextColumn::make('overflowAgent.name')
                    ->label('Overflow AI')
                    ->placeholder('None'),
                Tables\Columns\TextColumn::make('threads_count')
                    ->label('Open threads')
                    ->counts([
                        'threads' => fn ($q) => $q->whereNotIn('status', ['closed']),
                    ])
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('name')
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

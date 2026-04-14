<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\KnowledgeStoreResource\Pages;
use App\Jobs\IngestKnowledgeJob;
use App\Jobs\ReindexKnowledgeStoreJob;
use App\Models\KnowledgeStore;
use App\Services\Knowledge\EmbeddingService;
use BackedEnum;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Platform cross-tenant editor for knowledge stores. Stores themselves
 * are tenant-scoped (BelongsToTeam) but super-admin sees the whole set
 * across every customer from this single resource.
 *
 * Ingest actions (upload file, paste text, add URL) dispatch
 * IngestKnowledgeJob onto the queue so the UI stays responsive even on
 * large documents.
 */
class KnowledgeStoreResource extends Resource
{
    protected static ?string $model = KnowledgeStore::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Conversational AI';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Knowledge Stores';

    protected static ?string $pluralModelLabel = 'Knowledge Stores';

    protected static ?string $modelLabel = 'Knowledge Store';

    protected static ?string $slug = 'knowledge-stores';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('team');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Store')
                ->description('Core metadata. Tenant ownership is fixed after creation.')
                ->icon('heroicon-o-identification')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('team_id')
                        ->label('Tenant')
                        ->relationship('team', 'name')
                        ->required()
                        ->searchable()
                        ->disabledOn('edit'),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Customer FAQ'),
                    Forms\Components\Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('embedding_model')
                        ->label('Embedding model')
                        ->options([
                            'openai:text-embedding-3-small' => 'OpenAI — text-embedding-3-small (1536, hosted)',
                            'openai:text-embedding-3-large' => 'OpenAI — text-embedding-3-large (3072, hosted)',
                            'ollama:nomic-embed-text' => 'Ollama — nomic-embed-text (768, local)',
                            'ollama:mxbai-embed-large' => 'Ollama — mxbai-embed-large (1024, local)',
                            'ollama:bge-m3' => 'Ollama — bge-m3 (1024, local, multilingual)',
                        ])
                        ->default(fn () => (string) config('services.embeddings.default_provider', 'openai:text-embedding-3-small'))
                        ->required()
                        ->disabledOn('edit')
                        ->helperText('Locked after creation. To change models, create a new store and re-ingest.')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            // Pre-populate dimensions so operators don't have to know.
                            if (filled($state)) {
                                $dims = app(EmbeddingService::class)->dimensions($state);
                                $set('embedding_dims', $dims);
                            }
                        }),
                    Forms\Components\TextInput::make('embedding_dims')
                        ->label('Embedding dimensions')
                        ->numeric()
                        ->required()
                        ->disabledOn('edit')
                        ->helperText('Auto-populated from the selected model.'),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Tenant')
                    ->sortable(),
                Tables\Columns\TextColumn::make('embedding_model')
                    ->badge()
                    ->fontFamily('mono')
                    ->size(TextSize::Small),
                Tables\Columns\TextColumn::make('chunk_count')
                    ->label('Chunks')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('ingest_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'idle' => 'success',
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Tenant')
                    ->relationship('team', 'name', fn ($query) => $query->where('personal_team', false)),
            ])
            ->actions([
                \Filament\Actions\Action::make('ingest')
                    ->label('Ingest')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('source_type')
                            ->options([
                                'text' => 'Paste text',
                                'file' => 'Upload file',
                                'url' => 'Fetch URL',
                            ])
                            ->default('text')
                            ->live()
                            ->required(),
                        Forms\Components\Textarea::make('payload_text')
                            ->label('Text')
                            ->rows(8)
                            ->visible(fn (Forms\Get $get) => $get('source_type') === 'text')
                            ->required(fn (Forms\Get $get) => $get('source_type') === 'text'),
                        Forms\Components\FileUpload::make('payload_file')
                            ->label('File')
                            ->acceptedFileTypes(['application/pdf', 'text/plain', 'text/markdown'])
                            ->disk('local')
                            ->directory('knowledge-ingest')
                            ->visibility('private')
                            ->visible(fn (Forms\Get $get) => $get('source_type') === 'file')
                            ->required(fn (Forms\Get $get) => $get('source_type') === 'file'),
                        Forms\Components\TextInput::make('payload_url')
                            ->label('URL')
                            ->url()
                            ->visible(fn (Forms\Get $get) => $get('source_type') === 'url')
                            ->required(fn (Forms\Get $get) => $get('source_type') === 'url'),
                        Forms\Components\TextInput::make('source_ref')
                            ->label('Source label')
                            ->maxLength(255)
                            ->helperText('Optional. Shown in search results as the citation.'),
                    ])
                    ->action(function (array $data, KnowledgeStore $record) {
                        $payload = match ($data['source_type']) {
                            'text' => $data['payload_text'] ?? '',
                            'file' => Storage::disk('local')->path($data['payload_file']),
                            'url' => $data['payload_url'] ?? '',
                        };

                        IngestKnowledgeJob::dispatch(
                            storeId: $record->id,
                            sourceType: $data['source_type'],
                            payload: $payload,
                            sourceRef: $data['source_ref'] ?? null,
                        );

                        $record->update(['ingest_status' => 'processing']);

                        Notification::make()
                            ->title('Ingest queued')
                            ->body('The store status will flip to idle when ingestion completes.')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\Action::make('reindex')
                    ->label('Reindex')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reindex this store?')
                    ->modalDescription('Re-embeds every existing chunk using the store\'s currently configured embedding model. Queues a background job — the store status will flip to idle when the reindex completes.')
                    ->action(function (KnowledgeStore $record) {
                        ReindexKnowledgeStoreJob::dispatch($record->id);
                        $record->update(['ingest_status' => 'processing']);

                        Notification::make()
                            ->title('Reindex queued')
                            ->body('The store will be reindexed in the background.')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKnowledgeStores::route('/'),
            'create' => Pages\CreateKnowledgeStore::route('/create'),
            'edit' => Pages\EditKnowledgeStore::route('/{record}/edit'),
        ];
    }
}

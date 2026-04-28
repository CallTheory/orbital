<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Team;
use App\Services\Clients\ClientPermissionGatekeeper;
use App\Services\Voicemail\VoicemailTranscriber;
use BackedEnum;
use Database\Seeders\PermissionCatalogSeeder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $modelLabel = 'Client';

    protected static ?string $pluralModelLabel = 'Clients';

    protected static ?string $recordTitleAttribute = 'name';

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

    /**
     * Clients are non-personal teams. Personal teams (created by Jetstream
     * for owners) are platform-internal and not exposed in the client list.
     */
    public static function getEloquentQuery(): Builder
    {
        return Team::query()->where('personal_team', false);
    }

    /**
     * Client detail form. The simpler the better — sub-pages handle the rest.
     * Quotas and permission ceiling stay here because they're client-level
     * settings, not lists of related records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('Client')
                    ->tabs([
                        Tab::make('Details')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('account_number')
                                    ->label('Account Number')
                                    ->numeric()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Customer-facing identifier. Manually assigned.'),
                                // Owner is only required at CREATE time (the
                                // Team needs an initial user_id), and it's
                                // captured here so the create flow stays
                                // one-step. Once the client exists, the
                                // Users tab is the authoritative surface —
                                // portal users and additional members are
                                // managed via team_user pivot rows there.
                                Forms\Components\Select::make('user_id')
                                    ->label('Initial Owner')
                                    ->relationship('owner', 'name')
                                    ->searchable()
                                    ->required()
                                    ->helperText('The client\'s first portal user. After create, invite additional users via the Users tab.')
                                    ->visibleOn('create'),
                                Forms\Components\Select::make('timezone')
                                    ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                                    ->searchable()
                                    ->placeholder('App default ('.config('app.timezone').')')
                                    ->helperText('Timezone used when displaying dates to operators handling this client\'s messages and calls.'),
                                // The DB column is `suspended_at`
                                // (nullable timestamp): NULL = enabled,
                                // any timestamp = suspended at that
                                // moment. We surface it as a yes/no
                                // Toggle so operators don't have to
                                // think about timestamps — flipping
                                // off stamps `now()`, flipping on
                                // clears the column.
                                Forms\Components\Toggle::make('suspended_at')
                                    ->label('Enabled')
                                    ->default(true)
                                    ->afterStateHydrated(function (Forms\Components\Toggle $component, ?Team $record): void {
                                        // Read the raw column off the record —
                                        // the toggle's `$state` argument has
                                        // already been cast to bool by the time
                                        // afterStateHydrated runs, so it can't
                                        // distinguish null from a timestamp.
                                        // Null => enabled (on); any timestamp
                                        // => suspended (off).
                                        $component->state($record === null || $record->suspended_at === null);
                                    })
                                    ->dehydrateStateUsing(fn (bool $state) => $state ? null : now())
                                    ->helperText('Suspended clients can\'t place or receive calls.'),
                                Forms\Components\Hidden::make('personal_team')
                                    ->default(false),
                            ]),

                        Tab::make('Quotas')
                            ->icon('heroicon-o-scale')
                            ->schema([
                                Forms\Components\Placeholder::make('quotas_help')
                                    ->content('Clients are read-only customer accounts; the platform owns their SIP trunks and extensions. Only concurrent-call capacity is exposed as a quota.')
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('max_concurrent_calls')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Unlimited')
                                    ->helperText('Maximum number of simultaneous calls this client can have in flight. Leave blank for unlimited.'),
                            ]),

                        Tab::make('Permission Ceiling')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\Placeholder::make('ceiling_help')
                                    ->content('Permissions client-side users can have. Default deployments have nothing configurable here — clients are read-only customer accounts.')
                                    ->columnSpanFull(),
                                Forms\Components\CheckboxList::make('allowed_permissions')
                                    ->label('Allowed Permissions')
                                    ->options(self::groupedPermissionOptions())
                                    ->columns(2)
                                    ->bulkToggleable()
                                    ->columnSpanFull()
                                    ->afterStateHydrated(function (Forms\Components\CheckboxList $component, ?Team $record) {
                                        if ($record) {
                                            $names = app(ClientPermissionGatekeeper::class)
                                                ->allowedPermissionsFor($record);
                                            $component->state($names);
                                        }
                                    })
                                    ->dehydrated(false),
                            ]),

                        Tab::make('Recording')
                            ->icon('heroicon-o-microphone')
                            ->schema([
                                Forms\Components\Placeholder::make('recording_help')
                                    ->content('Per-client overrides for call recording. Leave any field blank to inherit the platform default. Individual extensions can still override these via their own recording_mode field.')
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('recording_overrides.enabled')
                                    ->label('Recording')
                                    ->options([
                                        '' => 'Inherit platform default',
                                        '1' => 'Enabled',
                                        '0' => 'Disabled',
                                    ])
                                    // Stored as a real bool inside the
                                    // recording_overrides JSON. Without
                                    // this cast, `false` hydrates to the
                                    // empty string option ("Inherit")
                                    // instead of "Disabled".
                                    ->formatStateUsing(fn ($state) => self::triStateBool($state))
                                    ->native(false),
                                Forms\Components\Select::make('recording_overrides.format')
                                    ->label('Format')
                                    ->options([
                                        '' => 'Inherit platform default',
                                        'wav' => 'WAV (lossless)',
                                        'mp3' => 'MP3 (compressed)',
                                    ])
                                    ->native(false),
                                Forms\Components\TextInput::make('recording_overrides.retention_days')
                                    ->label('Retention (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Inherit platform default')
                                    ->helperText('0 = keep forever. Leave blank to inherit.'),
                                Forms\Components\Select::make('recording_overrides.beep_on_record')
                                    ->label('Beep when recording starts')
                                    ->options([
                                        '' => 'Inherit platform default',
                                        '1' => 'Yes',
                                        '0' => 'No',
                                    ])
                                    ->formatStateUsing(fn ($state) => self::triStateBool($state))
                                    ->native(false),
                                Forms\Components\TextInput::make('recording_overrides.beep_interval_seconds')
                                    ->label('Periodic beep interval (seconds)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Inherit platform default')
                                    ->helperText('Seconds between repeated notification beeps during an active recording. Required by some jurisdictions. 0 = only beep once at the start. Leave blank to inherit.'),
                                Forms\Components\Textarea::make('recording_overrides.disclosure_message')
                                    ->label('Disclosure message (TTS)')
                                    ->rows(3)
                                    ->placeholder('Inherit platform default')
                                    ->helperText('Optional text spoken to the caller at the start of a recorded call — e.g. "This call may be monitored or recorded for quality assurance." Leave blank to inherit the platform default.')
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Voicemail')
                            ->icon('heroicon-o-envelope-open')
                            ->schema([
                                Forms\Components\Placeholder::make('voicemail_help')
                                    ->content('Configure how voicemails left in this client\'s mailbox get transcribed before they are emailed out. Audio is always attached; transcripts are inline in the email body when a provider is selected.')
                                    ->columnSpanFull(),

                                Section::make('Greeting')
                                    ->description('What callers hear before the beep.')
                                    ->schema([
                                        Forms\Components\Select::make('voicemail_greeting_mode')
                                            ->label('Greeting mode')
                                            ->options([
                                                'asterisk_default' => 'Asterisk default (no custom greeting)',
                                                'custom_tts' => 'Custom TTS greeting',
                                            ])
                                            ->default('asterisk_default')
                                            ->native(false)
                                            ->live(),
                                        Forms\Components\Textarea::make('voicemail_greeting_text')
                                            ->label('Greeting text')
                                            ->rows(3)
                                            ->maxLength(1000)
                                            ->placeholder('Hi — you\'ve reached Acme Co. We can\'t take your call right now. Please leave your name, number, and a brief message after the beep.')
                                            ->helperText('Gets TTS-rendered into a WAV the Asterisk dialplan plays before the caller records. Re-rendered automatically on save.')
                                            ->visible(fn (Get $get) => $get('voicemail_greeting_mode') === 'custom_tts'),
                                        Forms\Components\Select::make('voicemail_greeting_voice_provider')
                                            ->label('Voice provider')
                                            ->options([
                                                'openai' => 'OpenAI (tts-1)',
                                                'elevenlabs' => 'ElevenLabs',
                                            ])
                                            ->default('openai')
                                            ->native(false)
                                            ->visible(fn (Get $get) => $get('voicemail_greeting_mode') === 'custom_tts'),
                                        Forms\Components\TextInput::make('voicemail_greeting_voice_id')
                                            ->label('Voice')
                                            ->helperText('OpenAI voices: alloy, echo, fable, onyx, nova, shimmer. ElevenLabs: paste a voice ID from your library.')
                                            ->placeholder('alloy')
                                            ->visible(fn (Get $get) => $get('voicemail_greeting_mode') === 'custom_tts'),
                                    ]),

                                Section::make('Transcription')
                                    ->description('Convert the recorded audio to text in the notification email.')
                                    ->schema([
                                        Forms\Components\Select::make('voicemail_transcription_provider')
                                            ->label('Transcription provider')
                                            ->options(VoicemailTranscriber::PROVIDERS)
                                            ->default('none')
                                            ->live()
                                            ->native(false)
                                            ->helperText('Pick "Whisper (local)" if you want to stay off the public internet; pick a cloud provider for higher accuracy or multilingual support.'),
                                        // Cloud provider API key — shown for any
                                        // provider that isn't "none" or the
                                        // local whisper.cpp server.
                                        Forms\Components\TextInput::make('voicemail_transcription_config.api_key')
                                            ->label('API key')
                                            ->password()
                                            ->revealable()
                                            ->helperText('Stored encrypted. Rotate by replacing the value here.')
                                            ->visible(fn (Get $get) => in_array(
                                                $get('voicemail_transcription_provider'),
                                                ['openai_whisper', 'deepgram', 'elevenlabs'],
                                                true,
                                            )),
                                        Forms\Components\TextInput::make('voicemail_transcription_config.model')
                                            ->label('Model')
                                            ->helperText('Optional — defaults to whisper-1 (OpenAI) or nova-2 (Deepgram).')
                                            ->visible(fn (Get $get) => in_array(
                                                $get('voicemail_transcription_provider'),
                                                ['openai_whisper', 'deepgram'],
                                                true,
                                            )),
                                        Forms\Components\TextInput::make('voicemail_transcription_config.model_id')
                                            ->label('Model ID')
                                            ->helperText('Optional — defaults to scribe_v1.')
                                            ->visible(fn (Get $get) => $get('voicemail_transcription_provider') === 'elevenlabs'),
                                        // Whisper (local) — pick one of the ggml
                                        // models baked into the whisper-local
                                        // image. The list here must stay in sync
                                        // with the WHISPER_MODELS compose build
                                        // arg; models not bundled will return a
                                        // clear HTTP 400 from the service with
                                        // the available set listed.
                                        Forms\Components\Select::make('voicemail_transcription_config.model')
                                            ->label('Whisper model')
                                            ->options([
                                                'tiny.en' => 'tiny.en — English-only, fastest (~75MB)',
                                                'tiny' => 'tiny — multilingual, fastest (~75MB)',
                                                'base.en' => 'base.en — English-only, balanced (~142MB)',
                                                'base' => 'base — multilingual, balanced (~142MB)',
                                                'small.en' => 'small.en — English-only, higher accuracy (~466MB)',
                                                'small' => 'small — multilingual, higher accuracy (~466MB)',
                                                'medium.en' => 'medium.en — English-only, slower (~1.5GB)',
                                                'medium' => 'medium — multilingual, slower (~1.5GB)',
                                                'large-v3-turbo' => 'large-v3-turbo — multilingual, best size/accuracy (~809MB)',
                                                'large-v3' => 'large-v3 — multilingual, highest accuracy (~3GB)',
                                            ])
                                            ->default('base.en')
                                            ->native(false)
                                            ->helperText('Models with `.en` only understand English. Multilingual variants accept any language whisper supports — leave Language blank to auto-detect.')
                                            ->visible(fn (Get $get) => $get('voicemail_transcription_provider') === 'whisper_local'),
                                        Forms\Components\Select::make('voicemail_transcription_config.language')
                                            ->label('Language')
                                            ->options([
                                                '' => 'Auto-detect (multilingual only)',
                                                'en' => 'English',
                                                'es' => 'Spanish',
                                                'fr' => 'French',
                                                'de' => 'German',
                                                'it' => 'Italian',
                                                'pt' => 'Portuguese',
                                                'nl' => 'Dutch',
                                                'pl' => 'Polish',
                                                'ru' => 'Russian',
                                                'uk' => 'Ukrainian',
                                                'zh' => 'Chinese',
                                                'ja' => 'Japanese',
                                                'ko' => 'Korean',
                                                'ar' => 'Arabic',
                                                'hi' => 'Hindi',
                                            ])
                                            ->native(false)
                                            ->default('')
                                            ->helperText('Forces whisper to interpret audio as this language. Leave blank to let the model decide (only works on multilingual models). Ignored entirely by `.en` models.')
                                            ->visible(fn (Get $get) => $get('voicemail_transcription_provider') === 'whisper_local'),
                                        Forms\Components\Placeholder::make('voicemail_local_note')
                                            ->content('Whisper (local) uses the in-cluster whisper-local service — no credentials needed, no outbound internet. Available models are set at image build time via the WHISPER_MODELS compose env var.')
                                            ->visible(fn (Get $get) => $get('voicemail_transcription_provider') === 'whisper_local'),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('dids'))
            ->columns([
                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account #')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('numbers')
                    ->label('Numbers')
                    ->state(fn (Team $record): HtmlString => new HtmlString(
                        view('filament.columns.client-numbers', ['record' => $record])->render()
                    ))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('dids', fn ($q) => $q->where('number', 'like', "%{$search}%"));
                    }),
                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean()
                    ->falseColor('gray')
                    ->getStateUsing(fn (Team $record) => $record->suspended_at === null),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('account_number')
            ->actions([
                EditAction::make()
                    ->label('Open'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Sub-navigation for a single client. Renders as a sidebar inside the
     * client context — DIDs, Extensions, Call Queues, etc. each get their
     * own page.
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            Pages\EditClient::class,
            Pages\ManageClientDids::class,
            Pages\ManageClientExtensions::class,
            // Channels hub replaces the four per-channel sidebar
            // entries. Call/Email/Message/Chat queue pages remain
            // route-reachable for deep links from elsewhere but
            // are not surfaced here.
            Pages\ManageClientChannels::class,
            Pages\ManageClientOrchestrations::class,
            Pages\ManageClientPersonas::class,
            Pages\ManageClientUsers::class,
            Pages\ManageClientDirectory::class,
            Pages\ManageClientDirectoryFields::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
            'dids' => Pages\ManageClientDids::route('/{record}/dids'),
            'extensions' => Pages\ManageClientExtensions::route('/{record}/extensions'),
            'channels' => Pages\ManageClientChannels::route('/{record}/channels'),
            'call-queues' => Pages\ManageClientCallQueues::route('/{record}/call-queues'),
            'email-queues' => Pages\ManageClientEmailQueues::route('/{record}/email-queues'),
            'message-queues' => Pages\ManageClientMessageQueues::route('/{record}/message-queues'),
            'chat-queues' => Pages\ManageClientChatQueues::route('/{record}/chat-queues'),
            'personas' => Pages\ManageClientPersonas::route('/{record}/personas'),
            'orchestrations' => Pages\ManageClientOrchestrations::route('/{record}/orchestrations'),
            'users' => Pages\ManageClientUsers::route('/{record}/users'),
            'directory' => Pages\ManageClientDirectory::route('/{record}/directory'),
            'directory-fields' => Pages\ManageClientDirectoryFields::route('/{record}/directory-fields'),
            'smart-ingest' => Pages\SmartIngestClientDirectory::route('/{record}/smart-ingest'),
        ];
    }

    /**
     * Build a CheckboxList options array keyed by resource group, excluding
     * platform-only permissions.
     *
     * @return array<string, string>
     */
    protected static function groupedPermissionOptions(): array
    {
        $options = [];
        foreach (PermissionCatalogSeeder::CATALOG as $name) {
            if (in_array($name, ClientPermissionGatekeeper::PLATFORM_ONLY, true)) {
                continue;
            }
            $options[$name] = $name;
        }

        return $options;
    }

    /**
     * Hydrate a recording-overrides tri-state bool into the exact string
     * option key its Select expects.
     *
     * The overrides column is cast as an array, so booleans round-trip
     * as real `true`/`false`. PHP's implicit string cast sends `false`
     * to `""` — which matches the "Inherit platform default" option
     * instead of "No" — so we translate explicitly before Filament
     * hydrates the field.
     *
     *   true   → '1'   (shows the positive option)
     *   false  → '0'   (shows the negative option)
     *   null   → ''    (shows "Inherit platform default")
     */
    protected static function triStateBool(mixed $state): string
    {
        if ($state === null || $state === '') {
            return '';
        }

        return $state ? '1' : '0';
    }
}

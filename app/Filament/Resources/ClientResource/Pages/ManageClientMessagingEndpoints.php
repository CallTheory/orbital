<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\MessageQueue;
use App\Services\Messaging\ProviderRegistry;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The numbers and shortcodes a client receives text traffic on.
 *
 * The messaging counterpart to ManageClientDids. Kept as a separate
 * page rather than a column on the DID list because the same number is
 * routinely voice with one carrier and SMS with another, and because
 * conflating them would mean a voice DID edit silently repointing text
 * traffic.
 *
 * An endpoint is the FIRST routing decision on every inbound message.
 * For carriers that have the concept, that decision is made on the
 * SENDER POOL — Twilio's Messaging Service and its equivalents — rather
 * than on a single number, and the pool is REQUIRED.
 *
 * That requirement is the compliance story for the whole channel.
 * Sending through a pool means the carrier enforces STOP/HELP/START on
 * every number in it, keeps each customer on one sticky sender, and
 * carries the A2P 10DLC registration a US long code needs to deliver at
 * all. A bare `From` number gets none of that, and the failure mode
 * isn't a bounced message — it's the carriers quietly de-registering
 * the number weeks later.
 *
 * It also removes an entire class of drift: the client adds numbers to
 * their pool in the carrier's console, and Orbital keeps routing them
 * correctly without anyone remembering to mirror the change here.
 */
class ManageClientMessagingEndpoints extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'messagingEndpoints';

    protected static ?string $modelLabel = 'messaging number';

    protected static ?string $pluralModelLabel = 'messaging numbers';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'Messaging Numbers';

    protected static ?string $title = 'Messaging Numbers';

    public static function getNavigationLabel(): string
    {
        return 'Messaging Numbers';
    }

    /**
     * Does the selected transport have a sender pool we should insist
     * on? Asked of the driver rather than hard-coded, because the
     * answer is genuinely different per carrier — an SMPP bind or a
     * WCTP pager gateway has nothing of the kind.
     */
    private static function requiresPool(mixed $provider): bool
    {
        $registry = app(ProviderRegistry::class);
        $key = (string) $provider;

        if ($key === '' || ! $registry->has($key)) {
            return false;
        }

        return $registry->get($key)->requiresSenderPool();
    }

    private static function poolLabel(mixed $provider): string
    {
        $registry = app(ProviderRegistry::class);
        $key = (string) $provider;

        if ($key === '' || ! $registry->has($key)) {
            return 'Sender pool';
        }

        return $registry->get($key)->senderPoolLabel();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('provider')
                ->label('Provider')
                ->options(fn () => app(ProviderRegistry::class)->options())
                ->required()
                ->native(false)
                ->live()
                ->default(fn () => array_key_first(app(ProviderRegistry::class)->options()))
                ->helperText('Which transport carries this traffic. The inbound webhook URL is /api/messaging/inbound/{provider}.'),

            Forms\Components\TextInput::make('sender_pool_id')
                ->label(fn (Get $get): string => self::poolLabel($get('provider')))
                ->placeholder('MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')
                ->maxLength(64)
                ->visible(fn (Get $get): bool => self::requiresPool($get('provider')))
                ->required(fn (Get $get): bool => self::requiresPool($get('provider')))
                // Two clients sharing a pool would route one client's
                // customers into the other's queue. The database says so
                // too; this is so the admin gets a sentence instead of a
                // constraint violation.
                ->unique(ignoreRecord: true)
                ->helperText(fn (Get $get): string => self::requiresPool($get('provider'))
                    ? 'Required. All sending goes through the pool, never a bare number — that is what makes the carrier honour STOP, keep a customer on one sticky sender, and apply the A2P campaign registration. Point the pool\'s inbound webhook at /api/messaging/inbound/'.((string) $get('provider') ?: '{provider}').' and every number in it routes here automatically.'
                    : ''),

            Forms\Components\TextInput::make('address')
                ->label(fn (Get $get): string => self::requiresPool($get('provider')) ? 'Display number (optional)' : 'Number or shortcode')
                ->placeholder('+15551234567')
                ->maxLength(64)
                ->required(fn (Get $get): bool => ! self::requiresPool($get('provider')))
                ->helperText(fn (Get $get): string => self::requiresPool($get('provider'))
                    ? 'Optional label only. A pool holds many numbers and the carrier picks which one sends, so nothing routes on this.'
                    : 'E.164 for phone numbers, bare digits for a shortcode, or the pager/WCTP identifier. Inbound matching tolerates missing "+" and country codes, so it does not have to match the carrier byte-for-byte.'),

            Forms\Components\Select::make('protocol')
                ->label('Protocol')
                ->options(array_combine(MessageQueue::PROTOCOLS, array_map('strtoupper', MessageQueue::PROTOCOLS)))
                ->default(MessageQueue::PROTOCOL_SMS)
                ->required()
                ->native(false)
                ->helperText(fn (Get $get): string => self::requiresPool($get('provider'))
                    ? 'Informational when a sender pool is set — the pool carries every protocol its numbers support, and inbound matches on the pool rather than the protocol. One endpoint per pool is all you need.'
                    : 'MMS arriving on an SMS endpoint is handled automatically — carriers use one number for both.'),

            Forms\Components\TextInput::make('label')
                ->maxLength(255)
                ->placeholder('Main line, After-hours pager, …'),

            Forms\Components\Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive numbers stop matching inbound messages. Existing conversations stay readable.'),

            Forms\Components\KeyValue::make('provider_config')
                ->label('Provider settings')
                ->keyLabel('Setting')
                ->valueLabel('Value')
                ->columnSpanFull()
                ->helperText('Optional per-endpoint overrides — account_sid, auth_token, status_callback. Leave empty to use the platform-wide credentials from config. Stored encrypted. The sender pool has its own field above and does not belong here.'),
        ]);
    }

    public function table(Table $table): Table
    {
        $editAction = Actions\EditAction::make();

        return $table
            ->recordTitleAttribute('address')
            ->columns([
                Tables\Columns\TextColumn::make('sender_pool_id')
                    ->label('Sender pool')
                    ->searchable()
                    ->weight('medium')
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->address)
                    ->action($editAction),

                Tables\Columns\TextColumn::make('label')
                    ->placeholder('—')
                    ->action($editAction),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),

                Tables\Columns\TextColumn::make('provider')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('threads_count')
                    ->label('Conversations')
                    ->counts('threads')
                    ->placeholder('0'),
            ])
            ->defaultSort('address')
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No messaging numbers')
            ->emptyStateDescription('Add the client\'s carrier sender pool — a Twilio Messaging Service or equivalent — then point its inbound webhook at /api/messaging/inbound/{provider}. Every number in the pool routes here without being listed individually.');
    }
}

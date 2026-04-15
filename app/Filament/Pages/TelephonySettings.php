<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\HoldMusicClass;
use App\Models\Team;
use App\Services\Settings\PlatformSettingsRepository;
use App\Services\Settings\TelephonySettingsKeys as Keys;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Editable telephony operational settings.
 *
 * Two sections:
 *   1. Unmatched Inbound Calls — what to do with calls whose dialed
 *      number doesn't match any tenant DID.
 *   2. Outage Handling — last-resort behavior when normal call paths
 *      can't be reached (LiveKit down, no operators, agent worker dead).
 *
 * Persists via PlatformSettingsRepository (DB-backed with cache).
 */
class TelephonySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Telephony Settings';

    protected ?string $subheading = 'What the PBX does with unmatched inbound calls and the last-resort fallbacks when normal call paths are unavailable.';

    protected static ?string $slug = 'telephony';

    protected string $view = 'filament.pages.telephony-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $repo = app(PlatformSettingsRepository::class);

        $this->form->fill([
            'unmatched_action' => $repo->get(Keys::UNMATCHED_ACTION, Keys::DEFAULTS[Keys::UNMATCHED_ACTION]),
            'unmatched_reject_code' => $repo->get(Keys::UNMATCHED_REJECT_CODE, Keys::DEFAULTS[Keys::UNMATCHED_REJECT_CODE]),
            'unmatched_message' => $repo->get(Keys::UNMATCHED_MESSAGE, Keys::DEFAULTS[Keys::UNMATCHED_MESSAGE]),
            'unmatched_catchall_tenant_id' => $repo->get(Keys::UNMATCHED_CATCHALL_TENANT_ID, Keys::DEFAULTS[Keys::UNMATCHED_CATCHALL_TENANT_ID]),

            'outage_max_hold_seconds' => $repo->get(Keys::OUTAGE_MAX_HOLD_SECONDS, Keys::DEFAULTS[Keys::OUTAGE_MAX_HOLD_SECONDS]),
            'outage_fallback_action' => $repo->get(Keys::OUTAGE_FALLBACK_ACTION, Keys::DEFAULTS[Keys::OUTAGE_FALLBACK_ACTION]),
            'outage_reject_code' => $repo->get(Keys::OUTAGE_REJECT_CODE, Keys::DEFAULTS[Keys::OUTAGE_REJECT_CODE]),
            'outage_message' => $repo->get(Keys::OUTAGE_MESSAGE, Keys::DEFAULTS[Keys::OUTAGE_MESSAGE]),
            'outage_notify_email' => $repo->get(Keys::OUTAGE_NOTIFY_EMAIL, Keys::DEFAULTS[Keys::OUTAGE_NOTIFY_EMAIL]),
            'outage_notify_cooldown_minutes' => $repo->get(Keys::OUTAGE_NOTIFY_COOLDOWN_MINUTES, Keys::DEFAULTS[Keys::OUTAGE_NOTIFY_COOLDOWN_MINUTES]),
            'outage_hold_music' => $repo->get(Keys::OUTAGE_HOLD_MUSIC, Keys::DEFAULTS[Keys::OUTAGE_HOLD_MUSIC]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Unmatched Inbound Calls')
                    ->icon('heroicon-o-question-mark-circle')
                    ->description('What happens when an inbound call arrives on a DID that does not match any tenant.')
                    ->schema([
                        Forms\Components\Select::make('unmatched_action')
                            ->label('Action')
                            ->options([
                                'reject' => 'Reject the call with a SIP code',
                                'play_message' => 'Play a message and hang up',
                                'route_to_tenant' => 'Forward to a specific tenant',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('unmatched_reject_code')
                            ->label('SIP rejection code')
                            ->options(Keys::clientRejectCodeOptions())
                            ->default(Keys::DEFAULTS[Keys::UNMATCHED_REJECT_CODE])
                            ->required()
                            ->helperText('Code returned to the carrier when a dialed number does not match any tenant DID.')
                            ->visible(fn (callable $get) => $get('unmatched_action') === 'reject'),
                        Forms\Components\Textarea::make('unmatched_message')
                            ->label('Message (TTS)')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn (callable $get) => $get('unmatched_action') === 'play_message'),
                        Forms\Components\Select::make('unmatched_catchall_tenant_id')
                            ->label('Catchall Tenant')
                            ->options(fn () => Team::query()
                                ->where('personal_team', false)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn (callable $get) => $get('unmatched_action') === 'route_to_tenant')
                            ->helperText('All unmatched inbound calls will be treated as belonging to this tenant.'),
                    ])
                    ->columns(2),

                Section::make('Outage Handling')
                    ->icon('heroicon-o-shield-exclamation')
                    ->description('Last-resort behavior when normal call paths fail (LiveKit unreachable, agent worker down, no operators logged in).')
                    ->schema([
                        Forms\Components\TextInput::make('outage_max_hold_seconds')
                            ->label('Max hold time')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('seconds')
                            ->helperText('How long to hold callers before falling back.'),
                        Forms\Components\Select::make('outage_hold_music')
                            ->label('Hold music')
                            ->options(fn () => HoldMusicClass::active()
                                ->orderBy('label')
                                ->pluck('label', 'name')
                                ->all())
                            ->required()
                            ->searchable()
                            ->helperText('Manage hold music sources in Telephony → Hold Music.'),
                        Forms\Components\Select::make('outage_fallback_action')
                            ->label('Fallback action')
                            ->options([
                                'voicemail' => 'Take voicemail',
                                'message' => 'Play message and hang up',
                                'reject' => 'Reject the call with a SIP code',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('outage_reject_code')
                            ->label('SIP rejection code')
                            ->options(Keys::serverRejectCodeOptions())
                            ->default(Keys::DEFAULTS[Keys::OUTAGE_REJECT_CODE])
                            ->required()
                            ->helperText('Code returned to the carrier when the platform cannot handle the call.')
                            ->visible(fn (callable $get) => $get('outage_fallback_action') === 'reject'),
                        Forms\Components\Textarea::make('outage_message')
                            ->label('Outage message (TTS)')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn (callable $get) => in_array($get('outage_fallback_action'), ['voicemail', 'message'], true)),
                    ])
                    ->columns(2),

                Section::make('Outage Notifications')
                    ->icon('heroicon-o-bell-alert')
                    ->description('Get alerted whenever outage handling fires, regardless of which fallback action is configured. Cooldown prevents alert storms.')
                    ->schema([
                        Forms\Components\TextInput::make('outage_notify_email')
                            ->label('Notify email')
                            ->email()
                            ->helperText('Address to alert when outage handling fires. Leave blank to disable.'),
                        Forms\Components\TextInput::make('outage_notify_cooldown_minutes')
                            ->label('Notification cooldown')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('Suppress repeat notifications within this window. The first alert in a window is sent immediately; subsequent triggers are counted and rolled into the next alert after the cooldown expires.'),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $repo = app(PlatformSettingsRepository::class);
        $repo->setMany([
            Keys::UNMATCHED_ACTION => $data['unmatched_action'],
            Keys::UNMATCHED_REJECT_CODE => (int) ($data['unmatched_reject_code'] ?? Keys::DEFAULTS[Keys::UNMATCHED_REJECT_CODE]),
            Keys::UNMATCHED_MESSAGE => $data['unmatched_message'] ?? null,
            Keys::UNMATCHED_CATCHALL_TENANT_ID => $data['unmatched_catchall_tenant_id'] ?? null,

            Keys::OUTAGE_MAX_HOLD_SECONDS => (int) ($data['outage_max_hold_seconds'] ?? Keys::DEFAULTS[Keys::OUTAGE_MAX_HOLD_SECONDS]),
            Keys::OUTAGE_FALLBACK_ACTION => $data['outage_fallback_action'],
            Keys::OUTAGE_REJECT_CODE => (int) ($data['outage_reject_code'] ?? Keys::DEFAULTS[Keys::OUTAGE_REJECT_CODE]),
            Keys::OUTAGE_MESSAGE => $data['outage_message'] ?? null,
            Keys::OUTAGE_NOTIFY_EMAIL => $data['outage_notify_email'] ?? null,
            Keys::OUTAGE_NOTIFY_COOLDOWN_MINUTES => (int) ($data['outage_notify_cooldown_minutes'] ?? Keys::DEFAULTS[Keys::OUTAGE_NOTIFY_COOLDOWN_MINUTES]),
            Keys::OUTAGE_HOLD_MUSIC => $data['outage_hold_music'] ?? 'default',
        ]);

        Notification::make()
            ->success()
            ->title('Telephony settings saved')
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }
}

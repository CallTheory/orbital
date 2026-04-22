<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersRegistrySettings;
use App\Services\Settings\PlatformSettingsRepository;
use App\Services\Settings\ServiceRestartCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * Editable platform-wide settings, persisted to the platform_settings
 * table and applied on top of Laravel's runtime config by
 * RuntimeConfigOverrideProvider. Super-admin only.
 *
 * The form is generated entirely from {@see SettingsRegistry} — adding a
 * new editable setting is a one-line change there with no form edits
 * required here.
 */
class PlatformSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;
    use RendersRegistrySettings;

    /**
     * Sections this page is responsible for. AI provider creds
     * moved to ProvidersSettings (Conversational AI → Providers);
     * the recording section moved to TelephonySettings.
     *
     * @var array<int, string>
     */
    protected array $sectionKeys = [
        'branding',
        'app',
        'mail',
        'inbound_mail',
        'broadcasting',
        'asterisk',
        'livekit',
        'agent_worker',
        'logging',
        'icecast',
        'sessions',
        'security',
        'telescope',
        'knowledge',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Platform Settings';

    protected ?string $subheading = 'Platform-wide configuration settings.';

    protected static ?string $slug = 'platform';

    protected string $view = 'filament.pages.platform-settings';

    /** @var array<string, mixed> Form state — keyed by registry setting key. */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->registrySectionState($this->sectionKeys));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema($this->registrySectionComponents($this->sectionKeys));
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    /**
     * Header actions:
     *   - Restart affected services — operator picks any subset of
     *     known service slugs and applies them in sequence. Available
     *     at any time, not just right after save, so an operator who
     *     edited .env directly can still trigger a reload from here.
     *   - Prune Telescope — destructive housekeeping for the built-in
     *     debug dashboard.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('restart_services')
                ->label('Restart affected services')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Forms\Components\CheckboxList::make('slugs')
                        ->label('Services to restart')
                        ->options(collect(ServiceRestartCatalog::handlers())
                            ->map(fn ($def, $slug) => $def['label'].' — '.$def['description'])
                            ->all())
                        ->required()
                        ->columns(1),
                ])
                ->modalHeading('Restart affected services')
                ->modalDescription('Pick the services whose config changed. Asterisk regenerates dialplan + `core reload` via AMI; Horizon calls `horizon:terminate` so the supervisor respawns workers.')
                ->modalSubmitActionLabel('Run')
                ->action(function (array $data): void {
                    $results = ServiceRestartCatalog::run((array) ($data['slugs'] ?? []));

                    foreach ($results as $r) {
                        Notification::make()
                            ->title($r['label'])
                            ->body($r['message'])
                            ->{$r['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }
                }),

            Action::make('prune_telescope')
                ->label('Prune Telescope entries')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Prune Telescope entries?')
                ->modalDescription(fn () => sprintf(
                    'This will permanently delete every Telescope entry older than %d hours.',
                    (int) (app(PlatformSettingsRepository::class)->get('telescope.retention_hours') ?? 48),
                ))
                ->modalSubmitActionLabel('Prune now')
                ->action(function () {
                    $hours = (int) (app(PlatformSettingsRepository::class)->get('telescope.retention_hours') ?? 48);

                    Artisan::call('telescope:prune', ['--hours' => $hours]);

                    Notification::make()
                        ->title('Telescope entries pruned')
                        ->body("Removed entries older than {$hours} hours.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function save(): void
    {
        $slugs = $this->saveRegistrySections($this->form->getState(), $this->sectionKeys);

        if ($slugs === []) {
            Notification::make()
                ->title('Settings saved')
                ->success()
                ->send();
            return;
        }

        $labels = ServiceRestartCatalog::labelsFor($slugs);
        Notification::make()
            ->title('Settings saved — restart required')
            ->body('The following services need a restart to pick up the change: '.implode(', ', $labels).'. Use the "Restart affected services" button at the top of the page.')
            ->warning()
            ->persistent()
            ->send();
    }
}

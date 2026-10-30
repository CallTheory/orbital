<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersRegistrySettings;
use App\Services\Settings\PlatformSettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
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
     * Sections this page is responsible for. Various sections have
     * spun off into their own pages:
     *   - branding       → System → Platform Branding
     *   - portal_branding → Preferences → Portal Branding
     *   - ai_providers   → Conversational AI → Providers
     *   - recording      → Telephony → Settings
     *
     * @var array<int, string>
     */
    protected array $sectionKeys = [
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
        'tracing',
        'error_reporting',
        'telescope',
        'knowledge',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

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
        // ~15 collapsible cards: keep Save pinned to the bottom of the
        // screen instead of below the last one.
        return $schema
            ->statePath('data')
            ->schema([
                ...$this->registrySectionComponents($this->sectionKeys),
                Actions::make($this->getFormActions())
                    ->alignment(Alignment::End)
                    ->sticky()
                    ->key('form-actions'),
            ]);
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
     *   - Restart services — the same prompt a save opens when a
     *     change needs one, available any time (e.g. after editing
     *     .env directly).
     *   - Prune Telescope — destructive housekeeping for the built-in
     *     debug dashboard.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->restartServicesAction(),

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

        $this->afterRegistrySave('Settings saved', $slugs);
    }
}

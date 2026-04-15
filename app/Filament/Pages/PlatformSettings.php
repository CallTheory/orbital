<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Settings\PlatformSettingsRepository;
use App\Services\Settings\SettingsRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
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
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 5;

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
        $repo = app(PlatformSettingsRepository::class);
        $state = [];

        foreach (SettingsRegistry::all() as $key => $def) {
            // Default: read the current effective config value (which may
            // already include a DB override from the boot provider, or fall
            // back to .env). Operators see exactly what the app is using
            // right now rather than blank fields.
            $configKey = $def['config_key'] ?? null;
            $current = $configKey ? config($configKey) : null;
            $persisted = $repo->get($key);

            $state[$this->fieldName($key)] = $persisted ?? $current;
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (SettingsRegistry::sections() as $sectionKey => $sectionMeta) {
            $fields = [];

            foreach (SettingsRegistry::forSection($sectionKey) as $key => $def) {
                $fields[] = $this->buildField($key, $def);
            }

            if (empty($fields)) {
                continue;
            }

            $sections[] = Section::make($sectionMeta['label'])
                ->description($sectionMeta['description'])
                ->icon($sectionMeta['icon'])
                ->collapsible()
                ->columns(2)
                ->schema($fields);
        }

        return $schema
            ->statePath('data')
            ->schema($sections);
    }

    /**
     * Build one Filament form component for a registry entry.
     */
    protected function buildField(string $key, array $def): Forms\Components\Field
    {
        $name = $this->fieldName($key);

        $field = match ($def['type']) {
            'password' => Forms\Components\TextInput::make($name)
                ->password()
                ->revealable()
                ->autocomplete('new-password'),
            'email' => Forms\Components\TextInput::make($name)->email(),
            'url' => Forms\Components\TextInput::make($name)->url(),
            'number' => Forms\Components\TextInput::make($name)->numeric(),
            'textarea' => Forms\Components\Textarea::make($name)->rows(3),
            'select' => Forms\Components\Select::make($name)->options($def['options'] ?? []),
            'toggle' => Forms\Components\Toggle::make($name),
            default => Forms\Components\TextInput::make($name),
        };

        $field->label($def['label'] ?? $key);

        if (! empty($def['placeholder'])) {
            $field->placeholder($def['placeholder']);
        }

        if (! empty($def['helper'])) {
            $field->helperText($def['helper']);
        }

        if (in_array($def['type'], ['textarea'], true)) {
            $field->columnSpanFull();
        }

        return $field;
    }

    /**
     * Convert a registry key (which may contain dots) into a flat form
     * field name. Filament treats dotted names as nested arrays, which
     * we don't want for this single-table state.
     */
    protected function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * Reverse of fieldName().
     */
    protected function originalKey(string $fieldName): string
    {
        return str_replace('__', '.', $fieldName);
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
     * Header actions: a destructive Telescope prune button that runs
     * `telescope:prune` with the configured retention window. Uses
     * `telescope.retention_hours` from the registry, falling back to 48h.
     */
    protected function getHeaderActions(): array
    {
        return [
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
        $state = $this->form->getState();
        $repo = app(PlatformSettingsRepository::class);

        $registry = SettingsRegistry::all();

        foreach ($state as $fieldName => $value) {
            $key = $this->originalKey($fieldName);
            if (! isset($registry[$key])) {
                continue;
            }

            // Empty input for a setting reverts it to the .env / config()
            // default by removing the DB row entirely. This way the form
            // never persists a hollow override that would shadow .env.
            if ($value === null || $value === '') {
                $repo->forget($key);
                continue;
            }

            $repo->set($key, $value);
        }

        // Apply the overrides to this request immediately so the success
        // notification (and any subsequent reads on the page) sees the
        // new values without waiting for a fresh boot.
        foreach ($state as $fieldName => $value) {
            $key = $this->originalKey($fieldName);
            $configKey = $registry[$key]['config_key'] ?? null;
            if ($configKey && $value !== null && $value !== '') {
                config()->set($configKey, $value);
            }
        }

        Notification::make()
            ->title('Settings saved')
            ->body('Long-running workers may need a restart to pick up infrastructure changes.')
            ->success()
            ->send();
    }
}

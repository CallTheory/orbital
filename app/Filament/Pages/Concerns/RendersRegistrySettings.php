<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Services\Settings\DeploymentDefaults;
use App\Services\Settings\PlatformSettingsRepository;
use App\Services\Settings\ServiceRestartCatalog;
use App\Services\Settings\SettingsRegistry;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Shared render / persist logic for pages that expose a subset of
 * the SettingsRegistry. Consumers declare which section keys they
 * own, call the three helpers from their own mount/form/save, and
 * get auto-generated forms driven by the registry.
 *
 * Used by:
 *   - PlatformSettings      (most sections)
 *   - ProvidersSettings     (ai_providers section only)
 *   - TelephonySettings     (recording section, appended to its
 *                            existing hardcoded form)
 */
trait RendersRegistrySettings
{
    /**
     * Read current persisted+effective values for every setting in
     * the listed sections. Caller merges this into the form's
     * Livewire state property before form->fill().
     *
     * @param  array<int, string>  $sectionKeys
     * @return array<string, mixed> field-name => value
     */
    public function registrySectionState(array $sectionKeys): array
    {
        $repo = app(PlatformSettingsRepository::class);
        $state = [];

        foreach ($sectionKeys as $sectionKey) {
            foreach (SettingsRegistry::forSection($sectionKey) as $key => $def) {
                $targets = $def['config_keys']
                    ?? (isset($def['config_key']) ? [$def['config_key']] : []);
                $current = $targets !== [] ? config($targets[0]) : null;
                $persisted = SettingsRegistry::isManagedByDeployment($key) ? null : $repo->get($key);

                $state[$this->registryFieldName($key)] = $persisted ?? $current;
            }
        }

        return $state;
    }

    /**
     * Build Filament Section components for the listed sections.
     * Returns an array suitable for splatting into Schema::schema().
     *
     * @param  array<int, string>  $sectionKeys
     * @return array<int, Section>
     */
    public function registrySectionComponents(array $sectionKeys): array
    {
        $sections = [];
        $meta = SettingsRegistry::sections();

        foreach ($sectionKeys as $sectionKey) {
            $sectionMeta = $meta[$sectionKey] ?? null;
            if ($sectionMeta === null) {
                continue;
            }

            $fields = [];
            foreach (SettingsRegistry::forSection($sectionKey) as $key => $def) {
                $fields[] = $this->buildRegistryField($key, $def);
            }
            if ($fields === []) {
                continue;
            }

            $section = Section::make($sectionMeta['label'])
                ->description($sectionMeta['description'])
                ->icon($sectionMeta['icon'])
                ->columns(2)
                ->schema($fields);

            // Pages with many sections (Platform Settings) want them
            // collapsible so operators can focus on one card at a time.
            // Single-section pages (Portal Branding) stay open — there's
            // nothing to collapse to.
            if (property_exists($this, 'collapsibleSections') ? $this->collapsibleSections : true) {
                $section = $section->collapsible();
            }

            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Persist the portion of $state that corresponds to registry
     * settings in the listed sections. Ignores keys outside the
     * registry so it's safe to call with a form state that mixes
     * registry-backed and hardcoded fields (as TelephonySettings does).
     *
     * Returns the set of `restart_required` service slugs for
     * settings whose value actually changed, so the caller can
     * surface a "restart X services" warning. Any change at all adds
     * `horizon`: queue workers read every setting once, at boot.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, string>  $sectionKeys
     * @return array<int, string>
     */
    public function saveRegistrySections(array $state, array $sectionKeys): array
    {
        $repo = app(PlatformSettingsRepository::class);
        $allowed = [];
        foreach ($sectionKeys as $sectionKey) {
            foreach (SettingsRegistry::forSection($sectionKey) as $key => $def) {
                $allowed[$key] = $def;
            }
        }

        $defaults = app(DeploymentDefaults::class);
        $restart = [];

        foreach ($state as $fieldName => $value) {
            $key = $this->registryOriginalKey($fieldName);
            if (! isset($allowed[$key]) || SettingsRegistry::isManagedByDeployment($key)) {
                continue;
            }
            $def = $allowed[$key];
            $targets = SettingsRegistry::configTargets($key);
            $default = $defaults->for($key);
            $defaults->remember($key, $default);
            $saved = $repo->get($key);
            $previous = $saved ?? $default;
            $incoming = ($value === null || $value === '') ? null : $value;

            // Only a value that differs from the deployment's is stored.
            // The form shows every field filled in, so storing them all
            // would freeze the deployment's current values: a later
            // .env / chart change (an in-cluster service address, say)
            // would then never take effect. Empty, or set back to the
            // deployment's value, means "follow the deployment".
            if ($incoming === null || $this->sameSettingValue($incoming, $default)) {
                if ($saved !== null) {
                    $repo->forget($key);
                }
                $effective = $default;
            } else {
                // No saved row yet means it must be written, whatever
                // the value (a toggle switched off is false, which a
                // comparison with "nothing saved" would call the same).
                if ($saved === null || ! $this->sameSettingValue($incoming, $saved)) {
                    $repo->set($key, $incoming);
                }
                $effective = $incoming;
            }

            if (! $this->sameSettingValue($previous, $effective)) {
                $restart['horizon'] = true;
                foreach ($def['restart_required'] ?? [] as $slug) {
                    $restart[$slug] = true;
                }
            }

            // Apply to runtime config immediately so the response
            // rendered after save sees the new values.
            foreach ($targets as $target) {
                config()->set($target, $effective);
            }
        }

        return array_keys($restart);
    }

    /**
     * Drop the value saved for $key so it follows the deployment
     * again, and apply that straight away like a save does.
     */
    public function resetRegistrySetting(string $key): void
    {
        $def = SettingsRegistry::find($key);
        if ($def === null || SettingsRegistry::isManagedByDeployment($key)) {
            return;
        }

        $repo = app(PlatformSettingsRepository::class);
        $defaults = app(DeploymentDefaults::class);
        $default = $defaults->for($key);
        $defaults->remember($key, $default);
        $saved = $repo->get($key);

        $repo->forget($key);
        foreach (SettingsRegistry::configTargets($key) as $target) {
            config()->set($target, $default);
        }
        $this->data[$this->registryFieldName($key)] = $default;

        $slugs = [];
        if (! $this->sameSettingValue($saved, $default)) {
            $slugs = array_values(array_unique(['horizon', ...($def['restart_required'] ?? [])]));
        }

        $this->afterRegistrySave('Reset to default', $slugs);
    }

    /**
     * Whether two setting values mean the same. Loose, so a form's "25"
     * matches a config 25 and true matches "1". Unset (null) matches
     * only unset or false: a toggle can't show "unset", so off and unset
     * are the same setting. Plain PHP == would also match 0 and "" to
     * null, so a number set to 0 would read as unchanged.
     */
    protected function sameSettingValue(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            $other = $a ?? $b;

            return $other === null || $other === false;
        }

        return $a == $b;
    }

    /**
     * How the deployment's value reads in the reset confirmation.
     */
    protected function describeDeploymentDefault(string $key): string
    {
        if (SettingsRegistry::isSecret($key)) {
            return 'The deployment\'s own value will apply (hidden, it\'s a secret).';
        }

        $default = app(DeploymentDefaults::class)->for($key);

        $shown = match (true) {
            $default === null, $default === '' => null,
            is_bool($default) => $default ? 'On' : 'Off',
            is_scalar($default) => (string) $default,
            default => null,
        };

        return $shown === null
            ? 'The deployment sets no value for it, so it will be empty.'
            : 'It will use the deployment\'s value: '.$shown;
    }

    /**
     * Tell the operator the save worked and apply what it needs.
     * Queue workers are restarted straight away (it's always safe);
     * anything else, like an Asterisk reload, opens the restart
     * prompt with those services ticked so it's one click, not a
     * scroll back to the top of the page.
     *
     * @param  array<int, string>  $slugs  from saveRegistrySections()
     */
    public function afterRegistrySave(string $title, array $slugs): void
    {
        $ok = true;
        $body = null;
        if (in_array('horizon', $slugs, true)) {
            [$result] = ServiceRestartCatalog::run(['horizon']);
            $ok = $result['ok'];
            $body = $result['message'];
        }
        Notification::make()
            ->title($title)
            ->body($body)
            ->{$ok ? 'success' : 'warning'}()
            ->persistent(! $ok)
            ->send();

        $remaining = array_values(array_diff($slugs, ['horizon']));
        if (ServiceRestartCatalog::labelsFor($remaining) !== []) {
            $this->mountAction('restartServices', ['slugs' => $remaining]);
        }
    }

    public function restartServicesAction(): Action
    {
        $handlers = ServiceRestartCatalog::handlers();

        return Action::make('restartServices')
            ->label('Restart services')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->fillForm(fn (array $arguments): array => ['slugs' => $arguments['slugs'] ?? []])
            ->schema([
                Forms\Components\CheckboxList::make('slugs')
                    ->label('Services to restart')
                    ->options(array_map(fn (array $def) => $def['label'], $handlers))
                    ->descriptions(array_map(fn (array $def) => $def['description'].' '.$def['impact'], $handlers))
                    ->required()
                    ->columns(1),
            ])
            ->modalHeading(fn (array $arguments): string => ($arguments['slugs'] ?? []) === []
                ? 'Restart services'
                : 'Restart services for this change?')
            ->modalDescription(fn (array $arguments): ?string => ($arguments['slugs'] ?? []) === []
                ? null
                : 'The settings are saved, but the services ticked below only pick them up after a restart.')
            ->modalSubmitActionLabel('Restart now')
            ->modalCancelActionLabel('Later')
            ->action(function (array $data): void {
                foreach (ServiceRestartCatalog::run((array) ($data['slugs'] ?? [])) as $r) {
                    Notification::make()
                        ->title($r['label'])
                        ->body($r['message'])
                        ->{$r['ok'] ? 'success' : 'danger'}()
                        ->send();
                }
            });
    }

    /**
     * Settings that other settings' `visible_when` depends on. They
     * re-render the form as they change so dependent fields appear
     * and disappear without a save.
     *
     * @return array<string, true>
     */
    protected function registryControllingKeys(): array
    {
        $keys = [];
        foreach (SettingsRegistry::all() as $def) {
            foreach (array_keys($def['visible_when'] ?? []) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * Field builder for a registry entry. Kept here so all three
     * pages render the same field types with the same helper-text
     * / restart-badge conventions.
     */
    protected function buildRegistryField(string $key, array $def): Forms\Components\Field
    {
        $name = $this->registryFieldName($key);

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
            // Rectangular image uploader for logos/favicons.
            // `->image()` turns on Filament's image-specific preview
            // + validation; `->avatar()` is deliberately NOT set
            // because logos are 512×128 rectangles and a circular
            // preview would clip them. Visibility('public') flags
            // the uploaded object so SeaweedFS serves it without
            // signing — logos and favicons are public-by-intent.
            'image' => Forms\Components\FileUpload::make($name)
                ->image()
                ->imagePreviewHeight('96')
                ->panelAspectRatio('4:1')
                ->panelLayout('integrated')
                ->disk($def['disk'] ?? 's3')
                ->directory($def['directory'] ?? 'branding')
                ->visibility('public')
                ->maxSize(2048)
                ->acceptedFileTypes($def['accept'] ?? [
                    'image/png',
                    'image/jpeg',
                    'image/svg+xml',
                    'image/x-icon',
                    'image/vnd.microsoft.icon',
                    'image/webp',
                ]),
            default => Forms\Components\TextInput::make($name),
        };

        $field->label($def['label'] ?? $key);

        if (isset($this->registryControllingKeys()[$key])) {
            $field->live();
        }

        // Shown only while another setting has one of the listed
        // values (e.g. SMTP fields only for the SMTP mailer). Hidden
        // fields aren't saved, so switching away and back keeps them.
        if (! empty($def['visible_when'])) {
            $field->visible(function (Get $get) use ($def): bool {
                foreach ($def['visible_when'] as $otherKey => $values) {
                    if (! in_array($get($this->registryFieldName($otherKey)), $values, true)) {
                        return false;
                    }
                }

                return true;
            });
        }

        if (! empty($def['placeholder'])) {
            $field->placeholder($def['placeholder']);
        }

        // Restart suffix: automatic on-field hint so the operator
        // sees the obligation inline with the setting, not just
        // after save.
        $helper = $def['helper'] ?? null;
        if (! empty($def['restart_required'])) {
            $labels = ServiceRestartCatalog::labelsFor($def['restart_required']);
            if ($labels !== []) {
                $badge = 'Requires restart: '.implode(', ', $labels).'.';
                $helper = $helper ? $helper.' '.$badge : $badge;
            }
        }
        if (SettingsRegistry::isManagedByDeployment($key)) {
            // Shown, not editable: the installer sets it for the whole
            // deployment (see config/orbital.php managed_settings).
            $field->disabled()->dehydrated(false);
            $helper = 'Set by this site\'s installer (./install.sh in orbital-setup). Change it there so the rest of the deployment follows.';
        } else {
            // Only offered while a value is saved here; otherwise the
            // field already follows the deployment.
            $field->hintAction(
                Action::make('resetToDefault')
                    ->label('Reset to default')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->visible(fn (): bool => app(PlatformSettingsRepository::class)->get($key) !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Reset "'.($def['label'] ?? $key).'" to default?')
                    ->modalDescription(fn (): string => 'This removes the value saved here, so the setting follows this site\'s deployment again. '
                        .$this->describeDeploymentDefault($key))
                    ->modalSubmitActionLabel('Reset')
                    ->action(fn () => $this->resetRegistrySetting($key)),
            );
        }
        if ($helper) {
            $field->helperText($helper);
        }

        // Only textarea spans full width. Image fields stay one-col
        // so a light/dark logo pair sits on the same row in a
        // ->columns(2) section.
        if (in_array($def['type'], ['textarea'], true)) {
            $field->columnSpanFull();
        }

        return $field;
    }

    protected function registryFieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    protected function registryOriginalKey(string $fieldName): string
    {
        return str_replace('__', '.', $fieldName);
    }
}

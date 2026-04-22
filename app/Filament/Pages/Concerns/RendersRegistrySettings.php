<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Services\Settings\PlatformSettingsRepository;
use App\Services\Settings\SettingsRegistry;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Hash;

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
     * @return array<string, mixed>  field-name => value
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
                $persisted = $repo->get($key);

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

            $sections[] = Section::make($sectionMeta['label'])
                ->description($sectionMeta['description'])
                ->icon($sectionMeta['icon'])
                ->collapsible()
                ->columns(2)
                ->schema($fields);
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
     * surface a "restart X services" warning.
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

        $restart = [];

        foreach ($state as $fieldName => $value) {
            $key = $this->registryOriginalKey($fieldName);
            if (! isset($allowed[$key])) {
                continue;
            }
            $def = $allowed[$key];
            $previous = $repo->get($key);
            $incoming = ($value === null || $value === '') ? null : $value;

            if ($incoming === null) {
                $repo->forget($key);
            } else {
                $repo->set($key, $incoming);
            }

            if (! empty($def['restart_required']) && $previous != $incoming) {
                foreach ($def['restart_required'] as $slug) {
                    $restart[$slug] = true;
                }
            }

            // Apply to runtime config immediately so the response
            // rendered after save sees the new values.
            $targets = $def['config_keys']
                ?? (isset($def['config_key']) ? [$def['config_key']] : []);
            if ($incoming !== null) {
                foreach ($targets as $target) {
                    config()->set($target, $incoming);
                }
            }
        }

        return array_keys($restart);
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
            default => Forms\Components\TextInput::make($name),
        };

        $field->label($def['label'] ?? $key);

        if (! empty($def['placeholder'])) {
            $field->placeholder($def['placeholder']);
        }

        // Restart suffix: automatic on-field hint so the operator
        // sees the obligation inline with the setting, not just
        // after save.
        $helper = $def['helper'] ?? null;
        if (! empty($def['restart_required'])) {
            $labels = \App\Services\Settings\ServiceRestartCatalog::labelsFor($def['restart_required']);
            if ($labels !== []) {
                $badge = 'Requires restart: '.implode(', ', $labels).'.';
                $helper = $helper ? $helper.' '.$badge : $badge;
            }
        }
        if ($helper) {
            $field->helperText($helper);
        }

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

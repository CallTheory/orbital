<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use Filament\Forms;
use Illuminate\Support\Collection;

/**
 * Translates a tenant's field-definition collection into Filament
 * form components keyed under `values.{slug}` so they read/write
 * directly into the model's `values` JSONB column.
 *
 * Used by ManageTenantContacts and ManageTenantDirectory — both pages
 * load definitions at form() time and hand them here. Field type →
 * component mapping is the only knowledge concentrated in this
 * class so adding a new field type is a single edit.
 *
 * The collection is consumed as-is (filtered to active definitions
 * and sorted by sort_order) — callers should pass the result of the
 * relation's eager-loaded query rather than re-filtering here.
 */
class FieldFormBuilder
{
    /**
     * @param  Collection<int, \App\Models\ContactFieldDefinition|\App\Models\DirectoryFieldDefinition>  $definitions
     * @return array<int, Forms\Components\Field>
     */
    public function build(Collection $definitions): array
    {
        return $definitions
            ->filter(fn ($def) => (bool) $def->is_active)
            ->sortBy('sort_order')
            ->map(fn ($def) => $this->buildOne($def))
            ->values()
            ->all();
    }

    protected function buildOne(object $def): Forms\Components\Field
    {
        $name = "values.{$def->key}";
        $field = match ($def->type) {
            'textarea' => Forms\Components\Textarea::make($name)->rows(3),
            'number' => Forms\Components\TextInput::make($name)->numeric(),
            'date' => Forms\Components\DatePicker::make($name)->native(false),
            'datetime' => Forms\Components\DateTimePicker::make($name)->native(false),
            'boolean' => Forms\Components\Toggle::make($name),
            'select' => Forms\Components\Select::make($name)
                ->native(false)
                ->options($this->labelOptions($def->options ?? [])),
            'multi_select' => Forms\Components\Select::make($name)
                ->multiple()
                ->native(false)
                ->options($this->labelOptions($def->options ?? [])),
            'email' => Forms\Components\TextInput::make($name)->email(),
            'url' => Forms\Components\TextInput::make($name)->url(),
            'phone' => Forms\Components\TextInput::make($name)->tel(),
            default => Forms\Components\TextInput::make($name),
        };

        $field
            ->label($def->label)
            ->required((bool) $def->required);

        if (filled($def->placeholder)) {
            $field->placeholder($def->placeholder);
        }
        if (filled($def->help_text)) {
            $field->helperText($def->help_text);
        }

        // Textarea looks awful in a 2-col layout — span full width.
        if ($def->type === 'textarea') {
            $field->columnSpanFull();
        }

        return $field;
    }

    /**
     * The select Repeater stores options as a flat string array.
     * Filament Select wants an associative `value => label` map. We
     * label each option with itself — operators can re-author later
     * if they want richer labels.
     *
     * @param  array<int, string>  $options
     * @return array<string, string>
     */
    protected function labelOptions(array $options): array
    {
        $out = [];
        foreach ($options as $opt) {
            $opt = (string) $opt;
            if ($opt === '') {
                continue;
            }
            $out[$opt] = $opt;
        }
        return $out;
    }
}

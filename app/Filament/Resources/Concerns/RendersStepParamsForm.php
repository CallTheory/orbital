<?php

declare(strict_types=1);

namespace App\Filament\Resources\Concerns;

use App\Models\IntakeGoal;
use App\Models\KnowledgeStore;
use Filament\Forms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Builds the per-step parameter form for whatever intake primitive
 * has been selected in a flow-step Repeater item.
 *
 * The goal's own row carries a `data_fields` array that documents the
 * shape of `step_params` — one entry per param with {key, label,
 * type, required, options?, hint?}. We walk that array and render the
 * matching Filament component for each entry, wired to live under the
 * `step_params.<key>` state path.
 *
 * Use from a Repeater item like so:
 *
 *     Forms\Components\Select::make('intake_goal_id')->live()
 *     RendersStepParamsForm::group()  // appended sibling
 */
trait RendersStepParamsForm
{
    /**
     * Return a Filament Group component whose schema re-renders based
     * on the current `intake_goal_id` selection. Call from any form
     * that places intake flow steps.
     */
    public static function stepParamsGroup(): Group
    {
        return Group::make()
            ->columnSpanFull()
            ->schema(fn (Get $get): array => static::buildStepParamsSchema((int) $get('intake_goal_id')));
    }

    /**
     * Resolve the goal's `data_fields` descriptor and return Filament
     * components matching it. `state_path` is auto-nested under
     * `step_params.<key>` so callers don't have to think about layout.
     *
     * @return array<int, Component>
     */
    protected static function buildStepParamsSchema(?int $goalId): array
    {
        if (! $goalId) {
            return [];
        }

        $goal = IntakeGoal::find($goalId);
        if (! $goal) {
            return [];
        }

        $fields = $goal->data_fields ?? [];
        if (empty($fields)) {
            return [];
        }

        $components = [];
        foreach ($fields as $field) {
            $component = static::componentForField($field);
            if ($component) {
                $components[] = $component;
            }
        }

        if ($components === []) {
            return [];
        }

        return [
            Section::make($goal->name.' parameters')
                ->description($goal->description ?: 'Configuration for this step.')
                ->schema($components)
                ->columns(2)
                ->collapsed(false),
        ];
    }

    /**
     * Map one data_fields descriptor entry to a Filament form component
     * under the step_params.<key> state path.
     */
    protected static function componentForField(array $field): ?Component
    {
        $key = $field['key'] ?? null;
        if (! is_string($key) || $key === '') {
            return null;
        }

        $path = "step_params.{$key}";
        $label = $field['label'] ?? ucwords(str_replace('_', ' ', $key));
        $required = (bool) ($field['required'] ?? false);
        $hint = $field['hint'] ?? null;
        $type = $field['type'] ?? 'string';

        $component = match ($type) {
            'textarea', 'expression' => Forms\Components\Textarea::make($path)->rows(3),
            'number' => Forms\Components\TextInput::make($path)->numeric(),
            'boolean' => Forms\Components\Toggle::make($path)->inline(false),
            'select' => Forms\Components\Select::make($path)
                ->options(array_combine($field['options'] ?? [], $field['options'] ?? []))
                ->native(false),
            'slot_list' => Forms\Components\TagsInput::make($path)
                ->placeholder('Add slot name (e.g. caller_name)')
                ->separator(','),
            'knowledge_store_list' => Forms\Components\Select::make($path)
                ->multiple()
                ->options(fn () => KnowledgeStore::query()->pluck('name', 'id'))
                ->searchable(),
            'time_window_list', 'step_ref' => Forms\Components\TextInput::make($path)
                ->placeholder('(visual editor coming; freeform for now)'),
            default => Forms\Components\TextInput::make($path),
        };

        $component = $component->label($label);
        if ($required) {
            $component = $component->required();
        }
        if ($hint) {
            $component = $component->helperText($hint);
        }

        return $component;
    }
}

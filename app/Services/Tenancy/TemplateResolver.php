<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the "effective" attribute values for template-linked instances.
 *
 * For records that link to a parent template (agent personas and scripts),
 * the instance stores only the fields that differ from the template in an
 * `overrides` JSON column. Reads go: override → template field → own column.
 *
 * Writes (from Filament forms) pass through computeOverrides() which diffs
 * the edited form state against the template and keeps only the differences
 * in `overrides`. When the platform operator updates a template, linked
 * instances automatically reflect the change unless a specific field is
 * overridden.
 */
class TemplateResolver
{
    /**
     * Fields considered "templatable" for each model. Non-listed fields
     * (team_id, template_id, overrides, timestamps, id) are always
     * instance-local and never resolved through the template.
     *
     * @var array<class-string, array<int, string>>
     */
    protected array $templatableFields = [
        \App\Models\AgentPersona::class => [
            'name',
            'role',
            'description',
            'avatar_path',
            'system_prompt',
            'greeting',
            'outbound_greeting',
            'personality',
            'voice_id',
            'voice_config',
            'llm_provider',
            'llm_model',
            'stt_provider',
            'tts_provider',
            'tools_config',
        ],
        \App\Models\IntakeGoal::class => [
            'key',
            'name',
            'description',
            'category',
            'icon',
            'talking_points',
            'data_fields',
            'completion',
            'tools',
            'knowledge_store_ids',
            'voice_overrides',
            'operator_overrides',
            'chat_overrides',
        ],
    ];

    /**
     * Resolve a single field on an instance, walking: override → template → own column.
     */
    public function resolve(Model $instance, string $field): mixed
    {
        $overrides = $instance->overrides ?? [];
        if (is_array($overrides) && array_key_exists($field, $overrides)) {
            return $overrides[$field];
        }

        if ($instance->template_id && $template = $instance->template) {
            return $template->{$field};
        }

        return $instance->{$field};
    }

    /**
     * Return the full set of effective attributes for the instance — merged with
     * the template. Used to pre-fill Filament edit forms.
     *
     * @return array<string, mixed>
     */
    public function effective(Model $instance): array
    {
        $fields = $this->templatableFieldsFor($instance);
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $this->resolve($instance, $field);
        }
        return $out;
    }

    /**
     * Given the full form payload, compute the `overrides` JSON to persist:
     * keep only fields that differ from the template. If the instance has no
     * template, store every templatable field on the row itself and return
     * an empty overrides array.
     *
     * @param  array<string, mixed>  $edited  Form payload keyed by field name
     * @return array{row: array<string, mixed>, overrides: array<string, mixed>|null}
     *         `row` goes directly into Model::fill(); `overrides` goes into the
     *         overrides column.
     */
    public function computeOverrides(Model $instance, array $edited): array
    {
        $fields = $this->templatableFieldsFor($instance);
        $template = $instance->template_id ? $instance->template : null;

        // Non-templated instance: persist everything on the row.
        if (! $template) {
            $row = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $edited)) {
                    $row[$field] = $edited[$field];
                }
            }
            return ['row' => $row, 'overrides' => null];
        }

        // Templated instance: store only differences.
        $overrides = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $edited)) {
                continue;
            }
            $templateValue = $template->{$field};
            if ($edited[$field] !== $templateValue) {
                $overrides[$field] = $edited[$field];
            }
        }

        return ['row' => [], 'overrides' => $overrides === [] ? null : $overrides];
    }

    /**
     * @return array<int, string>
     */
    protected function templatableFieldsFor(Model $instance): array
    {
        return $this->templatableFields[get_class($instance)] ?? [];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\ClientDid;
use App\Models\EmailQueue;
use App\Models\Extension;
use App\Models\KnowledgeStore;
use App\Models\Orchestration;
use App\Models\OrchestrationBinding;
use App\Models\Team;
use Filament\Forms;
use Filament\Schemas\Components\Component;

/**
 * Builds Filament form fields for an orchestration's bindings, scoped
 * to a specific client's resources. Used by per-client CallQueue and
 * EmailQueue pages so that when a queue points at a shared
 * orchestration, the operator can map each declared binding key to one
 * of the client's own personas / queues / extensions / etc.
 *
 * Three responsibilities:
 *   - `fields()`     — returns the Filament components for an
 *                      orchestration's binding definitions, with each
 *                      picker scoped to the running client.
 *   - `loadValues()` — fetches existing binding rows for the
 *                      (client, orchestration) pair, keyed by
 *                      binding_key, ready for the form's fillForm().
 *   - `save()`       — upserts the binding rows from a form-data
 *                      payload after a successful save.
 */
class OrchestrationBindingFormFactory
{
    /**
     * Filament components, one per binding key, scoped to the client's
     * resources. Keys produced live under `bindings.{binding_key}`.
     *
     * @return array<int, Component>
     */
    public function fields(Orchestration $orchestration, Team $client): array
    {
        $defs = $orchestration->bindingDefinitions();
        if ($defs === []) {
            return [
                Forms\Components\Placeholder::make('no_bindings')
                    ->label('')
                    ->content('This orchestration has no external resource references — no bindings to configure.'),
            ];
        }

        return collect($defs)
            ->map(fn (array $def) => $this->fieldForBinding($def, $client))
            ->all();
    }

    /**
     * Existing binding values for `(client, orchestration)`, keyed by
     * `binding_key` so Filament's `fillForm` can hydrate the matching
     * fields. Multi-resource bindings come back as arrays; single
     * resources as ints.
     *
     * @return array<string, mixed>
     */
    public function loadValues(Orchestration $orchestration, Team $client): array
    {
        $bindings = OrchestrationBinding::query()
            ->withoutGlobalScope('team')
            ->where('team_id', $client->id)
            ->where('orchestration_id', $orchestration->id)
            ->get();

        $values = [];
        foreach ($bindings as $binding) {
            $values[$binding->binding_key] = $binding->resource_ids !== null
                ? $binding->resource_ids
                : $binding->resource_id;
        }

        return ['bindings' => $values];
    }

    /**
     * Persist the form's `bindings.{binding_key} => value` map back
     * into `orchestration_bindings` rows for the (client, orchestration)
     * pair. Idempotent — upsert on the unique (team, orch, key) index.
     *
     * @param  array<string, mixed>  $formData  the full form payload from the modal
     */
    public function save(Orchestration $orchestration, Team $client, array $formData): void
    {
        $bindings = $formData['bindings'] ?? [];
        $defs = collect($orchestration->bindingDefinitions())->keyBy('binding_key');

        foreach ($bindings as $key => $value) {
            $def = $defs[$key] ?? null;
            if (! $def) {
                continue;
            }

            $payload = [
                'team_id' => $client->id,
                'orchestration_id' => $orchestration->id,
                'binding_key' => $key,
                'resource_type' => $def['resource_type'],
                'resource_id' => null,
                'resource_ids' => null,
            ];

            if ($value === null || $value === '' || $value === []) {
                // Empty selection — null both columns (binding intent
                // declared, but no concrete resource yet).
            } elseif (is_array($value)) {
                $payload['resource_ids'] = array_values(array_filter(
                    $value,
                    fn ($v) => $v !== null && $v !== '',
                ));
            } else {
                $payload['resource_id'] = (int) $value;
            }

            OrchestrationBinding::updateOrCreate(
                [
                    'team_id' => $client->id,
                    'orchestration_id' => $orchestration->id,
                    'binding_key' => $key,
                ],
                $payload,
            );
        }
    }

    /**
     * Build one form component for one binding definition. The select
     * options are scoped to the client's resources of the binding's
     * resource_type.
     */
    private function fieldForBinding(array $def, Team $client): Component
    {
        $key = $def['binding_key'];
        $type = $def['resource_type'];

        return match ($type) {
            OrchestrationBinding::TYPE_AGENT_PERSONA => Forms\Components\Select::make("bindings.{$key}")
                ->label("{$key} — agent persona")
                ->options(
                    AgentPersona::query()
                        ->withoutGlobalScope('team')
                        ->where('team_id', $client->id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id'),
                )
                ->searchable()
                ->placeholder('Pick a persona for this client'),

            OrchestrationBinding::TYPE_CALL_QUEUE => Forms\Components\Select::make("bindings.{$key}")
                ->label("{$key} — call queue")
                ->options(
                    CallQueue::query()
                        ->withoutGlobalScope('team')
                        ->where('team_id', $client->id)
                        ->orderBy('name')
                        ->pluck('name', 'id'),
                )
                ->searchable()
                ->placeholder('Pick a call queue for this client'),

            OrchestrationBinding::TYPE_EMAIL_QUEUE => Forms\Components\Select::make("bindings.{$key}")
                ->label("{$key} — email queue")
                ->options(
                    EmailQueue::query()
                        ->withoutGlobalScope('team')
                        ->where('team_id', $client->id)
                        ->orderBy('name')
                        ->pluck('name', 'id'),
                )
                ->searchable()
                ->placeholder('Pick an email queue for this client'),

            OrchestrationBinding::TYPE_EXTENSION => Forms\Components\Select::make("bindings.{$key}")
                ->label("{$key} — extension(s)")
                ->multiple()
                ->options(
                    Extension::query()
                        ->withoutGlobalScope('team')
                        ->where('team_id', $client->id)
                        ->orderBy('number')
                        ->get()
                        ->mapWithKeys(fn (Extension $e) => [$e->id => $e->number.($e->label ? ' — '.$e->label : '')]),
                )
                ->searchable()
                ->placeholder('Pick one or more extensions'),

            OrchestrationBinding::TYPE_DID_SET => Forms\Components\Select::make("bindings.{$key}")
                ->label("{$key} — DIDs")
                ->multiple()
                ->options(
                    ClientDid::query()
                        ->where('team_id', $client->id)
                        ->where('is_active', true)
                        ->orderBy('number')
                        ->get()
                        ->mapWithKeys(fn (ClientDid $d) => [$d->id => $d->number.($d->label ? ' — '.$d->label : '')]),
                )
                ->searchable()
                ->placeholder('Pick the DIDs to match'),

            OrchestrationBinding::TYPE_KNOWLEDGE_STORE => Forms\Components\Select::make("bindings.{$key}")
                ->label("{$key} — knowledge store(s)")
                ->multiple()
                ->options(
                    KnowledgeStore::query()
                        ->where('team_id', $client->id)
                        ->orderBy('name')
                        ->pluck('name', 'id'),
                )
                ->searchable()
                ->placeholder('Pick stores the orchestration may search'),

            default => Forms\Components\TextInput::make("bindings.{$key}")
                ->label($key.' — '.$type)
                ->disabled()
                ->helperText('Unsupported resource type — surface in code, not the UI.'),
        };
    }
}

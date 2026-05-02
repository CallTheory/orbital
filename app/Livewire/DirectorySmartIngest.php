<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\DirectoryEntry;
use App\Models\DirectoryFieldDefinition;
use App\Models\SharedDirectory;
use App\Models\Team;
use App\Services\Contacts\SmartIngestClient;
use App\Services\Contacts\SmartIngestExtractor;
use Filament\Notifications\Notification;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Chat-style smart ingest for Directory entries.
 *
 * The operator drops an arbitrary input — file of any format or
 * pasted text — and Claude parses it into structured rows shaped
 * to the target parent's own field schema. The operator can chat
 * back with corrections ("merge rows 2 and 3", "the 'role' column
 * should map to 'title'") and the component feeds each correction
 * back to Claude to get an updated set. When happy, the operator
 * hits "Import" and the rows are committed as DirectoryEntry rows
 * under the right parent column.
 *
 * Parent is pluggable: a tenant (Team) or a SharedDirectory.
 *   - parentType = 'team'             → DirectoryEntry + team_id
 *   - parentType = 'shared_directory' → DirectoryEntry + shared_directory_id
 *
 * State lives on the component; refreshing the page loses it.
 * That's fine for v1 — this is a short-session flow.
 */
class DirectorySmartIngest extends Component
{
    use WithFileUploads;

    /** @var 'team'|'shared_directory' */
    public string $parentType = 'team';

    public int $parentId;

    public string $parentName = '';

    /**
     * Hard-coded for the directory ingest flow. The view's existing
     * $kind === 'directory' conditionals stay happy without forking
     * the template.
     */
    public string $kind = 'directory';

    public string $pastedText = '';

    public $file = null;

    /** @var array<int, array{role: string, content: mixed, display: string}> */
    public array $transcript = [];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public string $lastAssistantNote = '';

    public bool $processing = false;

    public ?string $error = null;

    /** @var array<int, array<string, mixed>> */
    public array $definitions = [];

    public string $schemaBlock = '';

    /** @var array<int, string> */
    public array $allowedKeys = [];

    /** @var array<string, string> */
    public array $labelByKey = [];

    public function mount(int $parentId, string $parentType = 'team'): void
    {
        $this->parentType = in_array($parentType, ['team', 'shared_directory'], true) ? $parentType : 'team';
        $this->parentId = $parentId;
        $this->parentName = $this->resolveParentName();
        $this->loadDefinitions();
    }

    protected function resolveParentName(): string
    {
        if ($this->parentType === 'shared_directory') {
            return SharedDirectory::find($this->parentId)?->name ?? 'Shared directory';
        }

        return Team::withoutGlobalScope('team')->find($this->parentId)?->name ?? 'Tenant';
    }

    /**
     * Pulls active definitions for this parent, then pre-computes
     * the schema block (for the LLM prompt) and the allowed-key list
     * (for sanitizeRow). Stored as plain arrays so Livewire can
     * serialize them across requests.
     */
    protected function loadDefinitions(): void
    {
        $query = DirectoryFieldDefinition::query()
            ->withoutGlobalScope('team')
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($this->parentType === 'shared_directory') {
            $query->where('shared_directory_id', $this->parentId);
        } else {
            $query->where('team_id', $this->parentId);
        }

        $collection = $query->get();

        $this->schemaBlock = app(SmartIngestClient::class)->formatSchemaForPrompt($collection);

        $defs = [];
        $keys = [];
        $labels = [];
        foreach ($collection as $def) {
            $defs[] = [
                'key' => $def->key,
                'label' => $def->label,
                'type' => $def->type,
                'role' => $def->role,
                'required' => (bool) $def->required,
                'options' => $def->options ?? [],
            ];
            $keys[] = $def->key;
            $labels[$def->key] = $def->label;
        }

        $this->definitions = $defs;
        $this->allowedKeys = $keys;
        $this->labelByKey = $labels;
    }

    /**
     * Kick off the first parse. Handles file + optional pasted text;
     * at least one must be set. Subsequent corrections go through
     * {@see reviseWithCorrection()}.
     */
    public function parse(): void
    {
        $this->error = null;
        $this->processing = true;

        try {
            if (empty($this->definitions)) {
                $this->error = 'This directory has no field definitions yet. Define some fields on the Directory Fields page first.';

                return;
            }

            $extractor = app(SmartIngestExtractor::class);
            $client = app(SmartIngestClient::class);

            $text = trim($this->pastedText);
            $imageBase64 = null;
            $imageMediaType = null;
            $fileSummary = '';

            if ($this->file) {
                $extracted = $extractor->extract(
                    $this->file->getRealPath(),
                    $this->file->getClientOriginalName(),
                );
                $fileSummary = $extracted['summary'];
                if ($extracted['image_base64'] !== null) {
                    $imageBase64 = $extracted['image_base64'];
                    $imageMediaType = $extracted['image_media_type'];
                } else {
                    $text = trim($text."\n\n".$extracted['text']);
                }
            }

            if ($text === '' && $imageBase64 === null) {
                $this->error = 'Please upload a file or paste some text first.';

                return;
            }

            $this->transcript[] = [
                'role' => 'user',
                'display' => $fileSummary !== '' ? $fileSummary.($text ? "\n".substr($text, 0, 200).'…' : '') : substr($text, 0, 400),
                'content' => $imageBase64 ? [
                    [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => $imageMediaType, 'data' => $imageBase64],
                    ],
                    ['type' => 'text', 'text' => $text],
                ] : $text,
            ];

            $result = $client->parse($this->schemaBlock, $text, $imageBase64, $imageMediaType);
            $this->rows = $result['rows'];
            $this->lastAssistantNote = $result['notes'];

            $this->transcript[] = [
                'role' => 'assistant',
                'display' => $result['notes'] !== '' ? $result['notes'] : 'Parsed '.count($this->rows).' row(s).',
                'content' => json_encode($result),
            ];

            $this->reset(['file', 'pastedText']);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    public function reviseWithCorrection(): void
    {
        $correction = trim($this->pastedText);
        if ($correction === '') {
            $this->error = 'Type a correction in the input below, then click Revise.';

            return;
        }

        $this->error = null;
        $this->processing = true;

        try {
            $prior = array_map(
                fn ($turn) => ['role' => $turn['role'], 'content' => $turn['content']],
                $this->transcript,
            );

            $this->transcript[] = [
                'role' => 'user',
                'display' => $correction,
                'content' => $correction,
            ];

            $result = app(SmartIngestClient::class)
                ->revise($this->schemaBlock, $prior, $correction);

            $this->rows = $result['rows'];
            $this->lastAssistantNote = $result['notes'];

            $this->transcript[] = [
                'role' => 'assistant',
                'display' => $result['notes'] !== '' ? $result['notes'] : 'Updated — now '.count($this->rows).' row(s).',
                'content' => json_encode($result),
            ];

            $this->reset(['pastedText']);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    public function commit(): void
    {
        if (empty($this->rows)) {
            $this->error = 'Nothing to import yet. Paste or upload something first.';

            return;
        }

        $inserted = 0;
        $parentColumn = $this->parentType === 'shared_directory' ? 'shared_directory_id' : 'team_id';

        foreach ($this->rows as $row) {
            $clean = $this->sanitizeRow($row);
            if (empty($clean)) {
                continue;
            }

            DirectoryEntry::create([
                $parentColumn => $this->parentId,
                'values' => $clean,
            ]);
            $inserted++;
        }

        $label = $this->parentType === 'shared_directory'
            ? 'shared directory entries'
            : 'directory entries';

        Notification::make()
            ->title("Imported {$inserted} {$label}")
            ->success()
            ->send();

        $this->reset(['transcript', 'rows', 'lastAssistantNote']);
    }

    public function clearSession(): void
    {
        $this->reset(['transcript', 'rows', 'lastAssistantNote', 'error', 'pastedText', 'file']);
    }

    /**
     * Filter an LLM-produced row down to the keys this parent has
     * actually defined. Defense against hallucinated fields. Values
     * are coerced to scalars; complex array values (e.g. multi_select)
     * pass through as-is.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function sanitizeRow(array $row): array
    {
        $out = [];
        foreach ($this->allowedKeys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = is_array($value) ? $value : (is_scalar($value) ? $value : null);
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }

    public function render()
    {
        return view('livewire.directory-smart-ingest');
    }
}

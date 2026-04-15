<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\DirectoryEntry;
use App\Models\DirectoryFieldDefinition;
use App\Models\SharedContactList;
use App\Models\Team;
use App\Services\Contacts\SmartIngestClient;
use App\Services\Contacts\SmartIngestExtractor;
use Filament\Notifications\Notification;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Chat-style smart ingest for Contacts and Directory.
 *
 * The operator drops an arbitrary input — file of any format or
 * pasted text — and Claude parses it into structured rows shaped to
 * the target parent's own field schema. The operator can chat back
 * with corrections ("merge rows 2 and 3", "the 'role' column should
 * map to 'title'") and the component feeds each correction back to
 * Claude to get an updated set.
 *
 * When happy, the operator hits "Import" and the rows are committed
 * to the matching model (Contact / DirectoryEntry) with the correct
 * parent column set.
 *
 * Parent is pluggable: a tenant (Team) or a shared contact list.
 * `parentType` selects which; `parentId` points at the row.
 *   - parentType = 'team'                 → Contact + team_id (or DirectoryEntry)
 *   - parentType = 'shared_contact_list'  → Contact + shared_contact_list_id (kind must be 'contacts')
 *
 * State lives on the component; refreshing the page loses it. That's
 * fine for v1 — this is a short-session flow.
 */
class ContactSmartIngest extends Component
{
    use WithFileUploads;

    /** @var 'contacts'|'directory' */
    public string $kind = 'contacts';

    /** @var 'team'|'shared_contact_list' */
    public string $parentType = 'team';

    public int $parentId;

    public string $parentName = '';

    /** Text input pasted by the operator. */
    public string $pastedText = '';

    /** File upload bound to the drop zone. */
    public $file = null;

    /** @var array<int, array{role: string, content: mixed}> */
    public array $transcript = [];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public string $lastAssistantNote = '';

    public bool $processing = false;

    public ?string $error = null;

    /**
     * The active definitions for this tenant + kind, plus a string
     * schema block ready to drop into the system prompt and a flat
     * list of allowed keys for sanitizeRow(). Loaded once on mount;
     * if the operator changes definitions in another tab the
     * component picks them up on the next page load.
     *
     * @var array<int, array<string, mixed>>  serializable shape
     */
    public array $definitions = [];

    public string $schemaBlock = '';

    /** @var array<int, string> */
    public array $allowedKeys = [];

    /** @var array<string, string> */
    public array $labelByKey = [];

    /**
     * @param  int  $parentId  ID of the team or shared list we're ingesting into
     * @param  string  $kind  'contacts' or 'directory' (directory requires parentType=team)
     * @param  string  $parentType  'team' or 'shared_contact_list'
     */
    public function mount(int $parentId, string $kind = 'contacts', string $parentType = 'team'): void
    {
        $this->parentType = in_array($parentType, ['team', 'shared_contact_list'], true) ? $parentType : 'team';
        $this->parentId = $parentId;
        $this->kind = in_array($kind, ['contacts', 'directory'], true) ? $kind : 'contacts';

        // Shared lists only support Contact — there's no shared
        // Directory model yet. If the caller asks for directory on
        // a shared list, fall back to contacts so the UI doesn't
        // render an empty schema.
        if ($this->parentType === 'shared_contact_list' && $this->kind === 'directory') {
            $this->kind = 'contacts';
        }

        $this->parentName = $this->resolveParentName();
        $this->loadDefinitions();
    }

    protected function resolveParentName(): string
    {
        if ($this->parentType === 'shared_contact_list') {
            $list = SharedContactList::find($this->parentId);
            return $list?->name ?? 'Shared contact list';
        }

        $team = Team::withoutGlobalScope('team')->find($this->parentId);
        return $team?->name ?? 'Tenant';
    }

    /**
     * Pulls active definitions for this parent + kind, then
     * pre-computes the schema block (for the LLM prompt) and the
     * allowed-key list (for sanitizeRow). Stored as plain arrays so
     * Livewire can serialize them across requests.
     */
    protected function loadDefinitions(): void
    {
        if ($this->parentType === 'shared_contact_list') {
            // Shared lists: scope on shared_contact_list_id, bypass
            // the team scope since super-admins are the only ones
            // who ever see this page.
            $collection = ContactFieldDefinition::query()
                ->withoutGlobalScope('team')
                ->where('shared_contact_list_id', $this->parentId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } else {
            $collection = $this->kind === 'directory'
                ? DirectoryFieldDefinition::query()->where('team_id', $this->parentId)->where('is_active', true)->orderBy('sort_order')->get()
                : ContactFieldDefinition::query()->where('team_id', $this->parentId)->where('is_active', true)->orderBy('sort_order')->get();
        }

        $this->schemaBlock = app(SmartIngestClient::class)->formatSchemaForPrompt($collection);

        $defs = [];
        $keys = [];
        $labels = [];
        foreach ($collection as $def) {
            $row = [
                'key' => $def->key,
                'label' => $def->label,
                'type' => $def->type,
                'role' => $def->role,
                'required' => (bool) $def->required,
                'options' => $def->options ?? [],
            ];
            $defs[] = $row;
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
     * {@see reviseWithCorrection()} instead.
     */
    public function parse(): void
    {
        $this->error = null;
        $this->processing = true;

        try {
            if (empty($this->definitions)) {
                $this->error = 'This tenant has no field definitions yet. Define some fields on the Contact Fields / Directory Fields page first.';
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

            // Record the user turn in the transcript so subsequent
            // revisions have the full context.
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

            $result = $client->parse($this->kind, $this->schemaBlock, $text, $imageBase64, $imageMediaType);
            $this->rows = $result['rows'];
            $this->lastAssistantNote = $result['notes'];

            $this->transcript[] = [
                'role' => 'assistant',
                'display' => $result['notes'] !== '' ? $result['notes'] : 'Parsed '.count($this->rows).' row(s).',
                'content' => json_encode($result),
            ];

            // Clear the upload slot so the next input starts fresh.
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
            // Build the message list Claude needs — transcript turns
            // minus the display wrappers.
            $prior = array_map(
                fn ($turn) => ['role' => $turn['role'], 'content' => $turn['content']],
                $this->transcript,
            );

            $this->transcript[] = [
                'role' => 'user',
                'display' => $correction,
                'content' => $correction,
            ];

            $client = app(SmartIngestClient::class);
            $result = $client->revise($this->kind, $this->schemaBlock, $prior, $correction);

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

        foreach ($this->rows as $row) {
            $clean = $this->sanitizeRow($row);
            if (empty($clean)) {
                continue;
            }

            if ($this->parentType === 'shared_contact_list') {
                // Shared list rows are platform-owned: no team_id,
                // all tenants attached to the list will see them
                // via the shared-pool scope on Contact.
                Contact::create([
                    'shared_contact_list_id' => $this->parentId,
                    'team_id' => null,
                    'values' => $clean,
                ]);
            } elseif ($this->kind === 'directory') {
                DirectoryEntry::create([
                    'team_id' => $this->parentId,
                    'values' => $clean,
                ]);
            } else {
                Contact::create([
                    'team_id' => $this->parentId,
                    'values' => $clean,
                ]);
            }
            $inserted++;
        }

        $label = match (true) {
            $this->parentType === 'shared_contact_list' => 'shared entries',
            $this->kind === 'directory' => 'directory entries',
            default => 'contacts',
        };

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
     * Filter an LLM-produced row down to the keys our tenant has
     * actually defined. Defense against hallucinated fields. Values
     * are coerced to scalars; complex array values (e.g. multi_select)
     * are passed through as-is.
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
        return view('livewire.contact-smart-ingest');
    }
}

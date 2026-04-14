{{--
    Chat-style smart ingest UI. Left column is a drop zone + textarea
    + action buttons; right column is the transcript with the user's
    turns and Claude's parsed-row responses.

    Uses scoped .osi-* CSS (orbital-smart-ingest) defined inline below
    because, as with the rest of the app, Tailwind utility classes
    don't resolve inside Filament's precompiled bundle without a
    custom panel theme.
--}}
@verbatim
<style>
    .osi-root { display: grid; grid-template-columns: 1fr; gap: 1.5rem; color: var(--gray-900); }
    .dark .osi-root { color: var(--gray-100); }
    @media (min-width: 1024px) { .osi-root { grid-template-columns: 1fr 1fr; } }

    .osi-panel {
        background: white;
        border: 1px solid var(--gray-200);
        border-radius: 0.75rem;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .dark .osi-panel { background: var(--gray-900); border-color: var(--gray-800); }

    .osi-panel-title {
        font-size: 0.875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--gray-500);
    }

    .osi-dropzone {
        border: 2px dashed var(--gray-300);
        border-radius: 0.5rem;
        padding: 1rem;
        background: var(--gray-50);
        font-size: 0.875rem;
    }
    .dark .osi-dropzone { border-color: var(--gray-700); background: var(--gray-950); }

    .osi-textarea {
        width: 100%;
        box-sizing: border-box;
        min-height: 8rem;
        padding: 0.625rem 0.75rem;
        font-family: inherit;
        font-size: 0.875rem;
        color: var(--gray-900);
        background: var(--gray-50);
        border: 1px solid var(--gray-200);
        border-radius: 0.5rem;
        resize: vertical;
    }
    .osi-textarea:focus {
        outline: none;
        border-color: var(--primary-500);
        box-shadow: 0 0 0 2px color-mix(in oklch, var(--primary-500), transparent 70%);
    }
    .dark .osi-textarea { background: var(--gray-950); border-color: var(--gray-800); color: var(--gray-100); }

    .osi-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .osi-btn {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        border-radius: 0.5rem;
        border: 0;
        cursor: pointer;
        color: white;
        font-family: inherit;
        transition: background-color 120ms, opacity 120ms;
    }
    .osi-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .osi-btn-primary { background: var(--primary-600); }
    .osi-btn-primary:hover:not(:disabled) { background: var(--primary-700); }
    .osi-btn-success { background: var(--success-600); }
    .osi-btn-success:hover:not(:disabled) { background: var(--success-700); }
    .osi-btn-ghost {
        background: transparent;
        color: var(--gray-600);
        border: 1px solid var(--gray-200);
    }
    .dark .osi-btn-ghost { color: var(--gray-400); border-color: var(--gray-800); }

    .osi-error {
        background: color-mix(in oklch, var(--danger-500), transparent 85%);
        color: var(--danger-700);
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem;
        font-size: 0.8125rem;
    }
    .dark .osi-error { color: var(--danger-400); }

    .osi-transcript { display: flex; flex-direction: column; gap: 0.75rem; max-height: 32rem; overflow-y: auto; }
    .osi-turn { padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; white-space: pre-wrap; }
    .osi-turn-user { background: var(--gray-50); border: 1px solid var(--gray-200); }
    .dark .osi-turn-user { background: var(--gray-950); border-color: var(--gray-800); }
    .osi-turn-assistant {
        background: color-mix(in oklch, var(--primary-500), transparent 92%);
        border: 1px solid color-mix(in oklch, var(--primary-500), transparent 70%);
    }
    .osi-turn-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--gray-500); margin-bottom: 0.25rem; }

    .osi-rows {
        margin-top: 0.5rem;
        border: 1px solid var(--gray-200);
        border-radius: 0.5rem;
        overflow: hidden;
    }
    .dark .osi-rows { border-color: var(--gray-800); }
    .osi-rows table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
    .osi-rows th, .osi-rows td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--gray-100); }
    .dark .osi-rows th, .dark .osi-rows td { border-bottom-color: var(--gray-800); }
    .osi-rows th { background: var(--gray-50); font-weight: 600; color: var(--gray-600); text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.05em; }
    .dark .osi-rows th { background: var(--gray-950); color: var(--gray-400); }
    .osi-rows tr:last-child td { border-bottom: 0; }

    .osi-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: var(--gray-500);
        font-size: 0.875rem;
    }

    .osi-spinner {
        display: inline-block;
        width: 0.875rem;
        height: 0.875rem;
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        animation: osi-spin 0.8s linear infinite;
        vertical-align: middle;
    }
    @keyframes osi-spin { to { transform: rotate(360deg); } }
</style>
@endverbatim

<div class="osi-root">
    {{-- Left column: input --}}
    <div class="osi-panel">
        <div class="osi-panel-title">
            {{ $kind === 'directory' ? 'Smart Ingest — Directory' : 'Smart Ingest — Contacts' }}
        </div>

        <p style="font-size: 0.875rem; color: var(--gray-600);">
            Drop any file (CSV, Excel, PDF, photo of a business card, .eml email) or paste text
            — Claude parses it into structured rows you can review and correct before committing.
        </p>

        <div class="osi-dropzone">
            <label style="display: block; font-weight: 600; margin-bottom: 0.25rem;">File (any format)</label>
            <input type="file" wire:model="file"
                   accept=".csv,.xlsx,.xls,.pdf,.eml,.txt,.jpg,.jpeg,.png,.gif,.webp">
            @error('file') <div class="osi-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label style="display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.875rem;">
                Or paste text / type a correction
            </label>
            <textarea wire:model.defer="pastedText" class="osi-textarea"
                      placeholder="Paste a block of contact info — or, after the first parse, type corrections like 'merge rows 2 and 3' or 'the role column should map to title'."></textarea>
        </div>

        @if ($error)
            <div class="osi-error">{{ $error }}</div>
        @endif

        <div class="osi-actions">
            @if (empty($transcript))
                <button type="button" wire:click="parse" @disabled($processing) class="osi-btn osi-btn-primary">
                    @if ($processing) <span class="osi-spinner"></span> Parsing… @else Parse with Claude @endif
                </button>
            @else
                <button type="button" wire:click="reviseWithCorrection" @disabled($processing) class="osi-btn osi-btn-primary">
                    @if ($processing) <span class="osi-spinner"></span> Revising… @else Revise @endif
                </button>
                <button type="button" wire:click="parse" @disabled($processing) class="osi-btn osi-btn-ghost">
                    Add more input
                </button>
            @endif

            @if (! empty($rows))
                <button type="button" wire:click="commit" @disabled($processing) class="osi-btn osi-btn-success">
                    Import {{ count($rows) }} {{ $kind === 'directory' ? 'entries' : 'contacts' }}
                </button>
            @endif

            @if (! empty($transcript))
                <button type="button" wire:click="clearSession" @disabled($processing) class="osi-btn osi-btn-ghost">
                    Start over
                </button>
            @endif
        </div>
    </div>

    {{-- Right column: transcript + current row set --}}
    <div class="osi-panel">
        <div class="osi-panel-title">Conversation</div>

        @if (empty($transcript))
            <div class="osi-empty">
                Nothing parsed yet. Drop a file or paste some text on the left and hit <strong>Parse with Claude</strong>.
            </div>
        @else
            <div class="osi-transcript">
                @foreach ($transcript as $turn)
                    <div class="osi-turn {{ $turn['role'] === 'user' ? 'osi-turn-user' : 'osi-turn-assistant' }}">
                        <div class="osi-turn-label">{{ $turn['role'] === 'user' ? 'You' : 'Claude' }}</div>
                        {{ is_string($turn['display']) ? $turn['display'] : '(attachment)' }}
                    </div>
                @endforeach
            </div>

            @if (! empty($rows))
                @php
                    // Union of every key Claude returned across all rows
                    // so the table covers sparsely-populated fields.
                    $allKeys = [];
                    foreach ($rows as $row) {
                        foreach (array_keys($row) as $k) {
                            $allKeys[$k] = true;
                        }
                    }
                    $columns = array_keys($allKeys);
                @endphp
                <div class="osi-rows">
                    <table>
                        <thead>
                            <tr>
                                @foreach ($columns as $col)
                                    <th>{{ $labelByKey[$col] ?? $col }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    @foreach ($columns as $col)
                                        <td>{{ is_array($row[$col] ?? null) ? implode(', ', $row[$col]) : ($row[$col] ?? '') }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</div>

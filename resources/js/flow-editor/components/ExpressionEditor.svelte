<script lang="ts">
    // Text-based expression editor with syntax-highlighting overlay.
    //
    //   mode="template"   — field is mostly text with `{{ expr }}` holes
    //   mode="expression" — the whole field is a single expression
    //
    // The editor is a <textarea> layered on top of a <pre> overlay.
    // The overlay mirrors the textarea's content but is syntax-
    // highlighted; the textarea is rendered with `color: transparent`
    // so only the overlay is visible. Scrolling is synced.
    //
    // Slot / function / context names light up in distinct colors.
    // Unknown identifiers render in zinc so authors notice typos.

    import {
        tokenizeExpression,
        tokenizeTemplate,
        renderHighlighted,
        FUNCTION_NAMES,
        CONTEXT_ROOTS,
        type KnownNames,
        type Mode,
    } from '../lib/expression';

    type Props = {
        value: string | null | undefined;
        slots: Array<{ name: string; type: string }>;
        mode?: Mode;
        placeholder?: string;
        rows?: number;
        onchange: (next: string) => void;
    };

    let {
        value,
        slots,
        mode = 'template',
        placeholder = '',
        rows = 3,
        onchange,
    }: Props = $props();

    // Accept legacy ExpressionValue-shaped props too (the old Phase 2
    // v1 stored objects). Coerce anything that isn't a string to
    // empty — the user can re-enter. This only affects data saved
    // during the short-lived v1 window.
    const coerce = (v: unknown): string => {
        if (v === null || v === undefined) return '';
        if (typeof v === 'string') return v;
        return '';
    };

    const initialText = coerce(value);
    let text = $state(initialText);
    let textarea: HTMLTextAreaElement | undefined = $state();
    let overlay: HTMLPreElement | undefined = $state();

    const known: KnownNames = $derived({
        slots: new Set(slots.map((s) => s.name)),
        contextRoots: new Set(CONTEXT_ROOTS),
        functions: new Set(FUNCTION_NAMES),
    });

    const tokens = $derived(
        mode === 'template'
            ? tokenizeTemplate(text, known)
            : tokenizeExpression(text, known),
    );
    const highlighted = $derived(renderHighlighted(tokens));

    function onInput() {
        onchange(text);
        syncScroll();
    }

    function syncScroll() {
        if (textarea && overlay) {
            overlay.scrollTop = textarea.scrollTop;
            overlay.scrollLeft = textarea.scrollLeft;
        }
    }

    function insertAtCursor(snippet: string) {
        if (!textarea) return;
        const start = textarea.selectionStart ?? text.length;
        const end = textarea.selectionEnd ?? text.length;
        text = text.slice(0, start) + snippet + text.slice(end);
        onchange(text);
        requestAnimationFrame(() => {
            if (!textarea) return;
            const caret = start + snippet.length;
            textarea.focus();
            textarea.setSelectionRange(caret, caret);
        });
    }

    /**
     * Wrap the current selection with `open…close`. If there's no
     * selection, insert `open close` and place the caret between them.
     * Used by function chips so clicking `upper` on selected text
     * `agent.name` produces `upper(agent.name)` in one step.
     */
    function wrapSelection(open: string, close: string) {
        if (!textarea) return;
        const start = textarea.selectionStart ?? text.length;
        const end = textarea.selectionEnd ?? text.length;
        const sel = text.slice(start, end);
        const inserted = open + sel + close;
        text = text.slice(0, start) + inserted + text.slice(end);
        onchange(text);
        requestAnimationFrame(() => {
            if (!textarea) return;
            // Empty selection: caret between open & close.
            // Non-empty: keep the selection inside the wrapper.
            const caretStart = sel === '' ? start + open.length : start + open.length;
            const caretEnd = sel === '' ? caretStart : start + open.length + sel.length;
            textarea.focus();
            textarea.setSelectionRange(caretStart, caretEnd);
        });
    }

    /**
     * Insert a template snippet with a placeholder marker `$1` that
     * gets auto-selected so the author can immediately overtype. Used
     * for the ternary / if-else chip.
     */
    function insertTemplate(snippet: string) {
        if (!textarea) return;
        const start = textarea.selectionStart ?? text.length;
        const end = textarea.selectionEnd ?? text.length;
        const marker = '$1';
        const markerAt = snippet.indexOf(marker);
        const clean = snippet.replace(marker, '');
        text = text.slice(0, start) + clean + text.slice(end);
        onchange(text);
        requestAnimationFrame(() => {
            if (!textarea) return;
            if (markerAt >= 0) {
                // Select the word that was where the marker sat so
                // the author can just start typing.
                const selStart = start + markerAt;
                const selEnd = selStart + nextWordLength(clean, markerAt);
                textarea.focus();
                textarea.setSelectionRange(selStart, selEnd);
            } else {
                const caret = start + clean.length;
                textarea.focus();
                textarea.setSelectionRange(caret, caret);
            }
        });
    }

    function nextWordLength(s: string, from: number): number {
        const m = s.slice(from).match(/^[A-Za-z_][A-Za-z0-9_]*/);
        return m ? m[0].length : 0;
    }

    let showHints = $state(false);
</script>

<div class="orbital-expr">
    <div class="orbital-expr__frame">
        <pre
            class="orbital-expr__overlay"
            class:is-template={mode === 'template'}
            bind:this={overlay}
            aria-hidden="true">{@html highlighted}</pre>
        <textarea
            class="orbital-expr__textarea"
            class:is-template={mode === 'template'}
            bind:this={textarea}
            bind:value={text}
            {rows}
            {placeholder}
            spellcheck="false"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            oninput={onInput}
            onscroll={syncScroll}
        ></textarea>
    </div>

    <div class="orbital-expr__meta">
        <span class="orbital-expr__mode">{mode === 'template' ? 'template' : 'expression'}</span>
        <button
            type="button"
            class="orbital-expr__hint-toggle"
            onclick={() => (showHints = !showHints)}
            title="Show slot / function chips"
        >{showHints ? 'hide hints' : 'hints'}</button>
    </div>

    {#if showHints}
        <div class="orbital-expr__hints">
            <div class="orbital-expr__legend">
                {#if mode === 'template'}
                    Wrap dynamic values in <code>{'{{ }}'}</code>.
                {/if}
                Select text, then click a function chip to wrap it
                (e.g. highlight <code>caller_phone</code> → click
                <code>format_phone</code>). For if-else, use the
                ternary: <code>cond ? then : else</code>.
            </div>

            <div class="orbital-expr__hint-group">
                {#if mode === 'template'}
                    <button
                        type="button"
                        class="orbital-expr__hint-chip is-brace"
                        onclick={() => wrapSelection('{{ ', ' }}')}
                        title="Wrap selection with {{ }} (or insert empty placeholder)"
                    >{'{{ sel }}'}</button>
                {/if}
                <button
                    type="button"
                    class="orbital-expr__hint-chip is-brace"
                    onclick={() => insertTemplate(mode === 'template' ? '{{ $1cond ? "yes" : "no" }}' : '$1cond ? "yes" : "no"')}
                    title="Insert if-else ternary"
                >if-else</button>
            </div>

            <span class="orbital-expr__hint-sep">slots:</span>
            {#each slots as s}
                <button
                    type="button"
                    class="orbital-expr__hint-chip is-slot"
                    onclick={() => insertAtCursor(mode === 'template' ? `{{ ${s.name} }}` : s.name)}
                    title={`slot (${s.type})`}
                >{s.name}</button>
            {/each}

            <span class="orbital-expr__hint-sep">context:</span>
            {#each CONTEXT_ROOTS as r}
                <button
                    type="button"
                    class="orbital-expr__hint-chip is-context"
                    onclick={() => insertAtCursor(mode === 'template' ? `{{ ${r} }}` : r)}
                >{r}</button>
            {/each}

            <span class="orbital-expr__hint-sep">functions (wrap selection):</span>
            {#each FUNCTION_NAMES.slice(0, 20) as f}
                <button
                    type="button"
                    class="orbital-expr__hint-chip is-fn"
                    onclick={() => wrapSelection(`${f}(`, ')')}
                    title={`Wrap selection with ${f}( … )`}
                >{f}</button>
            {/each}
        </div>
    {/if}
</div>

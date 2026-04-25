<script lang="ts">
    // A small condition builder: rows of (left, op, right), joined
    // by AND (the most common case by far). Null condition means
    // "fallback — always matches"; the "+ Add condition" button
    // materializes the first row.
    //
    // Round-trips JsonLogic: on mount we decompose a stored tree
    // into rows; on edit we compose rows back into JsonLogic and
    // write it to the bound `condition` prop.

    import type { SlotDTO } from '../lib/api';

    type Row = {
        left: string;      // slot path or context path, e.g. "slots.caller_name"
        op: string;
        right: string;     // literal (string-typed for v1)
    };

    const OPS = [
        { value: '==', label: '=' },
        { value: '!=', label: '≠' },
        { value: '<', label: '<' },
        { value: '<=', label: '≤' },
        { value: '>', label: '>' },
        { value: '>=', label: '≥' },
        { value: '!!', label: 'is set' },
        { value: '!', label: 'is not set' },
        { value: 'in', label: 'contains' },
        { value: 'startsWith', label: 'starts with' },
    ];

    const UNARY_OPS = new Set(['!!', '!']);

    let {
        condition = $bindable(null),
        slots,
        onChange,
    }: {
        condition: Record<string, unknown> | null;
        slots: SlotDTO[];
        onChange: () => void;
    } = $props();

    let rows: Row[] = $state(decompose(condition));

    function decompose(tree: Record<string, unknown> | null): Row[] {
        if (!tree) return [];
        // Single-row case: {op: [args]}
        const op = Object.keys(tree)[0];
        if (op === 'and' || op === 'AND') {
            const children = (tree[op] as unknown[]) ?? [];
            return children.map((c) => rowFromAtom(c as Record<string, unknown>)).filter(Boolean) as Row[];
        }
        const row = rowFromAtom(tree);
        return row ? [row] : [];
    }

    function rowFromAtom(atom: Record<string, unknown>): Row | null {
        const op = Object.keys(atom)[0];
        const args = (atom[op] as unknown[]) ?? [];
        if (UNARY_OPS.has(op)) {
            const left = (args[0] as Record<string, unknown> | undefined)?.var as string | undefined;
            return { left: left ?? '', op, right: '' };
        }
        const left = (args[0] as Record<string, unknown> | undefined)?.var as string | undefined;
        const right = typeof args[1] === 'string' || typeof args[1] === 'number' ? String(args[1]) : '';
        return { left: left ?? '', op, right };
    }

    function compose(rs: Row[]): Record<string, unknown> | null {
        const atoms = rs.filter((r) => r.left).map((r) =>
            UNARY_OPS.has(r.op)
                ? ({ [r.op]: [{ var: r.left }] } as Record<string, unknown>)
                : ({ [r.op]: [{ var: r.left }, coerceLiteral(r.right)] } as Record<string, unknown>),
        );
        if (atoms.length === 0) return null;
        if (atoms.length === 1) return atoms[0];
        return { and: atoms };
    }

    function coerceLiteral(raw: string): unknown {
        if (raw === 'true') return true;
        if (raw === 'false') return false;
        if (raw !== '' && !isNaN(Number(raw))) return Number(raw);
        return raw;
    }

    function push() {
        rows.push({ left: slots[0] ? `slots.${slots[0].name}` : '', op: '==', right: '' });
        sync();
    }

    function drop(i: number) {
        rows.splice(i, 1);
        sync();
    }

    function sync() {
        condition = compose(rows);
        onChange();
    }
</script>

<div style="display: flex; flex-direction: column; gap: 0.375rem;">
    {#if rows.length === 0}
        <button class="orbital-btn" onclick={push} style="align-self: flex-start;">
            + Add condition <span style="color: var(--oflow-soft); font-weight: 400;">(fallback otherwise)</span>
        </button>
    {:else}
        {#each rows as row, i (i)}
            <div style="display: flex; gap: 0.25rem; align-items: center;">
                <select
                    value={row.left}
                    onchange={(e) => { row.left = (e.currentTarget as HTMLSelectElement).value; sync(); }}
                    style="background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                >
                    <option value="">—</option>
                    <optgroup label="Variables">
                        {#each slots as slot}
                            <option value={`slots.${slot.name}`}>slots.{slot.name}</option>
                        {/each}
                    </optgroup>
                    <optgroup label="Context">
                        <option value="context.now.hour">context.now.hour</option>
                        <option value="context.now.weekday">context.now.weekday</option>
                        <option value="context.call.did">context.call.did</option>
                        <option value="context.caller.matched_id">context.caller.matched_id</option>
                    </optgroup>
                </select>
                <select
                    value={row.op}
                    onchange={(e) => { row.op = (e.currentTarget as HTMLSelectElement).value; sync(); }}
                    style="background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                >
                    {#each OPS as op}
                        <option value={op.value}>{op.label}</option>
                    {/each}
                </select>
                {#if !UNARY_OPS.has(row.op)}
                    <input
                        value={row.right}
                        oninput={(e) => { row.right = (e.currentTarget as HTMLInputElement).value; sync(); }}
                        placeholder="value"
                        style="flex: 1; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                    />
                {/if}
                <button class="orbital-btn" onclick={() => drop(i)} style="color: #fca5a5;">×</button>
            </div>
        {/each}
        <button class="orbital-btn" onclick={push} style="align-self: flex-start;">+ AND</button>
    {/if}
</div>

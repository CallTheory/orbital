<script lang="ts">
    // "Test this step" for Send Email / Web Call steps. Orbital runs
    // these after a call with what the AI captured; here the author
    // fills in (or keeps) made-up field values and runs the step as
    // it's configured right now — saved or not — to see it go through
    // before a client depends on it.

    import { testStep, type StepTestResult } from '../lib/api';

    let {
        orchestrationId,
        primitiveKey,
        params,
    }: {
        orchestrationId: number;
        primitiveKey: string;
        params: Record<string, unknown>;
    } = $props();

    // Fields every take-a-message flow captures, so they're offered
    // even when the templates don't mention them yet.
    const MESSAGE_FIELDS = ['caller_name', 'callback_number', 'reason'];
    const NOT_FIELDS = new Set(['true', 'false', 'null', 'now', 'call', 'context', 'caller', 'agent']);

    function referencedFields(p: Record<string, unknown>): string[] {
        const found = new Set<string>();
        for (const value of Object.values(p)) {
            if (typeof value !== 'string') continue;
            for (const [, expr] of value.matchAll(/\{\{([\s\S]*?)\}\}/g)) {
                // Strip string literals, then take bare identifiers
                // that aren't function names or context paths.
                const code = expr.replace(/"[^"]*"|'[^']*'/g, '');
                for (const m of code.matchAll(/(?<![\w.])([A-Za-z_]\w*)(?![\w.(])/g)) {
                    if (!NOT_FIELDS.has(m[1])) found.add(m[1]);
                }
            }
        }
        return [...found];
    }

    function sampleFor(name: string): string {
        if (/name/.test(name)) return 'Test Caller';
        if (/phone|number|callback/.test(name)) return '614 555 0100';
        if (/email/.test(name)) return 'test.caller@example.com';
        if (/reason|message|note|issue/.test(name)) return 'This is a test message from Orbital.';
        return `sample ${name.replace(/_/g, ' ')}`;
    }

    let fields: Array<{ key: string; value: string }> = $state(
        [...new Set([...MESSAGE_FIELDS, ...referencedFields(params)])].map((key) => ({ key, value: sampleFor(key) })),
    );
    let running = $state(false);
    let result: StepTestResult | null = $state(null);
    let error: string | null = $state(null);

    async function run() {
        running = true;
        result = null;
        error = null;
        try {
            result = await testStep(orchestrationId, {
                intake_goal_key: primitiveKey,
                step_params: params,
                fields: Object.fromEntries(fields.filter((f) => f.key.trim() !== '').map((f) => [f.key.trim(), f.value])),
            });
        } catch (e: unknown) {
            error = e instanceof Error ? e.message : String(e);
        } finally {
            running = false;
        }
    }
</script>

<section class="orbital-step-test">
    <h3 class="orbital-panel-heading">Test this step</h3>
    <p class="orbital-step-test__hint">
        {#if primitiveKey === 'send_email'}
            Sends this email now, as configured above, using these field values. The subject is marked "[Test]".
        {:else}
            Makes this web call now, as configured above, using these field values.
        {/if}
        Unsaved changes are included.
    </p>

    <div class="orbital-step-test__fields">
        {#each fields as field, i (i)}
            <input bind:value={field.key} placeholder="field" aria-label="Field name" />
            <input bind:value={field.value} placeholder="value" aria-label={`Value for ${field.key}`} />
            <button class="orbital-btn" onclick={() => fields.splice(i, 1)} aria-label={`Remove ${field.key}`}>×</button>
        {/each}
    </div>

    <div class="orbital-step-test__actions">
        <button class="orbital-btn" onclick={() => fields.push({ key: '', value: '' })}>+ Add field</button>
        <span style="flex: 1;"></span>
        <button class="orbital-btn is-primary" onclick={run} disabled={running}>
            {running ? 'Running…' : primitiveKey === 'send_email' ? 'Send test email' : 'Send test request'}
        </button>
    </div>

    {#if error}
        <div class="orbital-step-test__result is-fail">{error}</div>
    {/if}
    {#if result}
        <div class="orbital-step-test__result" class:is-ok={result.ok} class:is-fail={!result.ok}>
            <strong>{result.ok ? 'Worked' : 'Failed'}:</strong> {result.detail}
            {#if result.ok && result.preview.mailer === 'log'}
                <div>Outgoing mail isn't set up (Platform Settings → Mail), so this email was only written to the log.</div>
            {/if}
        </div>
        {#if result.preview.to || result.preview.url}
            <dl class="orbital-step-test__preview">
                {#if result.preview.to}
                    <dt>To</dt><dd>{result.preview.to.join(', ')}</dd>
                {/if}
                {#if result.preview.subject}
                    <dt>Subject</dt><dd>{result.preview.subject}</dd>
                {/if}
                {#if result.preview.url}
                    <dt>Request</dt><dd>{result.preview.method} {result.preview.url}</dd>
                {/if}
                {#if result.preview.body}
                    <dt>Body</dt><dd><pre>{result.preview.body}</pre></dd>
                {/if}
                {#if result.preview.response}
                    <dt>Response</dt><dd>HTTP {result.preview.response.status}{#if result.preview.response.body}<pre>{result.preview.response.body}</pre>{/if}</dd>
                {/if}
            </dl>
        {/if}
    {/if}
</section>

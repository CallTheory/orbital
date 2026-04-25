<script lang="ts">
    // Tiny modal primitive. Click the scrim to close. `wide` bumps
    // the width for forms that need room for a two-column param
    // layout (step editor). Esc key support via a window listener
    // that's set up only while the modal is open.

    import { onMount } from 'svelte';

    let {
        title,
        onClose,
        wide = false,
        huge = false,
        children,
    }: {
        title: string;
        onClose: () => void;
        wide?: boolean;
        huge?: boolean;
        children: () => unknown;
    } = $props();

    onMount(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    });
</script>

<div
    class="orbital-modal-scrim"
    onclick={onClose}
    onkeydown={(e) => { if (e.key === 'Escape') onClose(); }}
    role="presentation"
>
    <div
        class="orbital-modal"
        class:is-wide={wide}
        class:is-huge={huge}
        onclick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={title}
    >
        <div class="orbital-modal__header">
            <h2 class="orbital-modal__title">{title}</h2>
            <button
                type="button"
                class="orbital-editor-panel__close"
                onclick={onClose}
                aria-label="Close"
            >×</button>
        </div>
        <div class="orbital-modal__body">
            {@render children()}
        </div>
    </div>
</div>

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
    plugins: [
        laravel({
            // Separate Vite entry for the Svelte Flow editor so the
            // Svelte runtime + @xyflow/svelte bundle only ship on
            // the editor popup URL, not to the rest of the admin.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/flow-editor/main.ts',
            ],
            refresh: true,
        }),
        svelte({
            extensions: ['.svelte'],
        }),
    ],
});

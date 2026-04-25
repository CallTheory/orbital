// Entry point for the Svelte Flow editor — loaded by the blade shell
// at /admin/flow-editor/{graph}. Runs only on that popup URL; the
// rest of the admin panel doesn't ship this bundle.
//
// The root element is rendered by the blade view with
// `data-graph-id`, `data-graph-name`, `data-client-id`,
// `data-client-name` and an optional `data-focus-flow-id`. Those
// are passed to the Svelte app as props.

import { mount } from 'svelte';
import App from './App.svelte';
import '@xyflow/svelte/dist/style.css';
import './styles.css';

const host = document.getElementById('flow-editor-root');
if (!host) {
    throw new Error(
        'flow-editor: #flow-editor-root not found. Make sure the blade view includes the mount container.',
    );
}

const graphId = Number(host.dataset.graphId ?? '0');
if (!graphId) {
    throw new Error('flow-editor: missing data-graph-id on root element.');
}

const clientId = Number(host.dataset.clientId ?? '0');

const focusFlowIdRaw = host.dataset.focusFlowId;
const focusFlowId = focusFlowIdRaw ? Number(focusFlowIdRaw) : null;

mount(App, {
    target: host,
    props: {
        graphId,
        clientId,
        focusFlowId,
        graphName: host.dataset.graphName ?? 'Default',
        clientName: host.dataset.clientName ?? 'Client',
    },
});

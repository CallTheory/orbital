// Entry point for the Svelte Flow editor — loaded by the blade shell
// at /admin/flow-editor/{orchestration}. Runs only on that popup URL;
// the rest of the admin panel doesn't ship this bundle.
//
// The root element is rendered by the blade view with
// `data-orchestration-id`, `data-orchestration-name`,
// `data-client-id`, `data-client-name` and an optional
// `data-focus-flow-id`. Those are passed to the Svelte app as props.

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

const orchestrationId = Number(host.dataset.orchestrationId ?? '0');
if (!orchestrationId) {
    throw new Error('flow-editor: missing data-orchestration-id on root element.');
}

const isShared = host.dataset.isShared === '1';
const clientId = Number(host.dataset.clientId ?? '0');

const focusFlowIdRaw = host.dataset.focusFlowId;
const focusFlowId = focusFlowIdRaw ? Number(focusFlowIdRaw) : null;

mount(App, {
    target: host,
    props: {
        orchestrationId,
        isShared,
        clientId: isShared ? null : clientId,
        focusFlowId,
        orchestrationName: host.dataset.orchestrationName ?? 'Default',
        clientName: isShared ? 'Platform' : (host.dataset.clientName ?? 'Client'),
        closeUrl: host.dataset.closeUrl ?? '/admin/orchestrations',
    },
});

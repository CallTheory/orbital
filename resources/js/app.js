import './bootstrap';
import './sip-phone';
import softphone from './softphone';
import WaveSurfer from 'wavesurfer.js';

window.WaveSurfer = WaveSurfer;

// Register Alpine.data components as soon as Alpine is available.
// Filament bundles Alpine internally, so we attach on the documented
// `alpine:init` event which fires before Alpine starts processing the
// x-data directives on the page.
document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('softphone', softphone);
    }
});

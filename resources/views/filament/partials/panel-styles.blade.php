{{--
    Shared panel styles for all three Filament panels (admin, operator,
    portal). Each panel provider injects this partial via HEAD_END so
    the brand logo, dashboard grid, card metrics, and toast borders
    render consistently regardless of which surface the user is on.

    The <style> block below is wrapped in @verbatim so Blade doesn't
    try to parse CSS `@media` queries as control-flow directives. Blade
    reads `@media (...) { ... }` as an unknown/open directive and bails
    with a confusing "expected elseif/else/endif" error. @verbatim
    tells Blade "leave everything alone until @endverbatim".
--}}
@verbatim
<style>
    /* Toast notifications: full colored border matching the status
       variant. Filament uses `fi-status-{name}` on the notification
       root, NOT `fi-color-{name}` — the latter is a separate concept
       used by other components. */
    .fi-no-notification.fi-status-success {
        border: 2px solid rgb(16 185 129) !important; /* emerald-500 */
    }
    .fi-no-notification.fi-status-danger {
        border: 2px solid rgb(239 68 68) !important; /* red-500 */
    }
    .fi-no-notification.fi-status-warning {
        border: 2px solid rgb(245 158 11) !important; /* amber-500 */
    }
    .fi-no-notification.fi-status-info {
        border: 2px solid rgb(59 130 246) !important; /* blue-500 */
    }

    /* Top-of-page system health indicator — fed by the cached system
       health snapshot. Purely visual, not interactive. */
    .orbital-status-bar {
        display: block;
        width: 100%;
        height: 4px;
    }
    .orbital-status-bar.is-ok   { background: rgb(16 185 129); }
    .orbital-status-bar.is-warn { background: rgb(245 158 11); }
    .orbital-status-bar.is-down { background: rgb(220 38 38); }

    /* Dashboard health-check grid: 1 column on phone, 2 on tablet,
       3 on large desktop. Keeps the board dense at wide viewports
       without squeezing card metrics too tight. */
    .orbital-dashboard-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    @media (min-width: 768px) {
        .orbital-dashboard-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (min-width: 1280px) {
        .orbital-dashboard-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    /* Metric rows inside health-check cards: each key/value pair on
       its own line, smaller text, key rendered in a lighter gray to
       separate it visually from the value. */
    .orbital-card-metrics {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        margin-top: 0.625rem;
        padding-top: 0.375rem;
        font-size: 0.8125rem;
        line-height: 1.125rem;
    }
    .orbital-card-metrics > div {
        display: flex;
        align-items: baseline;
        gap: 0.375rem;
    }
    .orbital-card-metrics dt {
        color: rgb(107 114 128);
        font-weight: 500;
    }
    .orbital-card-metrics dd {
        color: rgb(31 41 55);
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .dark .orbital-card-metrics dt { color: rgb(107 114 128); }
    .dark .orbital-card-metrics dd { color: rgb(229 231 235); }

    /* Brand header: logo + wordmark. Default size is the sidebar size.
       On the login/simple layout we scale both up significantly so
       they read as a header. */
    .orbital-brand {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .orbital-brand-img {
        height: 1.75rem;
        width: auto;
    }
    .orbital-brand-text {
        font-weight: 600;
        font-size: 1.125rem;
    }
    .fi-simple-layout .orbital-brand {
        gap: 0.875rem;
        margin-bottom: 1.5rem;
    }
    .fi-simple-layout .orbital-brand-img {
        height: 4rem;
    }
    .fi-simple-layout .orbital-brand-text {
        font-size: 2.25rem;
        letter-spacing: -0.01em;
    }

    /* Shared dashboard grid used by the portal Dashboard, operator
       Workspace, and any future page that wants a 3-up layout with a
       2-wide primary card. Tailwind utility classes (grid-cols-*,
       gap-*, lg:col-span-*) don't resolve inside Filament panels
       without a custom theme, so these are plain CSS. */
    .orbital-page-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    @media (min-width: 1024px) {
        .orbital-page-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .orbital-page-grid-span-2 { grid-column: span 2 / span 2; }
    }

    .orbital-stat-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }
    .orbital-stat-card {
        border: 1px solid rgb(229 231 235);
        border-radius: 0.5rem;
        padding: 1rem;
    }
    .dark .orbital-stat-card { border-color: rgb(55 65 81); }
    .orbital-stat-label {
        font-size: 0.75rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: rgb(107 114 128);
    }
    .orbital-stat-value {
        margin-top: 0.25rem;
        font-size: 1.875rem;
        font-weight: 700;
        color: rgb(17 24 39);
    }
    .dark .orbital-stat-value { color: rgb(243 244 246); }

    .orbital-activity-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        font-size: 0.875rem;
    }
    .orbital-activity-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .orbital-activity-row .label {
        color: rgb(55 65 81);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .dark .orbital-activity-row .label { color: rgb(229 231 235); }
    .orbital-activity-row .when {
        flex-shrink: 0;
        font-size: 0.75rem;
        color: rgb(107 114 128);
    }

    /* Auth pages (login, forgot/reset password, 2FA, verify email)
       render raw <label> + <x-filament::input> pairs outside of
       Filament's form component, so the label's bottom spacing from
       the form component's Tailwind utility classes doesn't apply.
       Pad the label container manually so the label doesn't sit
       flush against the input below it. Scoped to the simple layout
       wrapper so it doesn't touch real Filament forms. */
    .fi-simple-layout .fi-fo-field-wrp-label-ctn {
        margin-bottom: 0.375rem;
    }

    /* Per-account security page forms (/{panel}/security).
       Filament's bundled CSS doesn't ship arbitrary Tailwind
       spacing utilities, so these classes give the 2FA / password
       / sessions forms proper vertical rhythm without pulling
       the app Tailwind bundle into every Filament page. */
    .orbital-form-stack {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    .orbital-form-field {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .orbital-form-field label {
        font-size: 0.875rem;
        font-weight: 500;
        color: rgb(55 65 81);
    }
    .dark .orbital-form-field label {
        color: rgb(209 213 219);
    }
    .orbital-form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    .orbital-section-stack {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    .orbital-text-muted {
        font-size: 0.875rem;
        line-height: 1.5;
        color: rgb(75 85 99);
    }
    .dark .orbital-text-muted {
        color: rgb(156 163 175);
    }

    /* Operator availability pill — rendered in the operator panel
       topbar via the TOPBAR_END render hook. Dot color tracks the
       operator's work status so it's readable at a glance without
       opening the dropdown. Available = green, everything else =
       amber/gray "don't ring me" states. */
    .orbital-availability {
        display: inline-flex;
        align-items: center;
    }
    .orbital-availability-label {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.375rem 0.75rem;
        background: var(--gray-50);
        border: 1px solid var(--gray-200);
        border-radius: 9999px;
        cursor: pointer;
    }
    .dark .orbital-availability-label {
        background: var(--gray-900);
        border-color: var(--gray-700);
    }
    .orbital-availability-label:hover {
        border-color: var(--gray-300);
    }
    .dark .orbital-availability-label:hover {
        border-color: var(--gray-600);
    }
    .orbital-availability-dot {
        width: 0.625rem;
        height: 0.625rem;
        border-radius: 9999px;
        flex-shrink: 0;
        /* Actual color is painted via inline style from the
           admin's color picker. No fixed palette here —
           whatever hex the admin saved on AvailabilityReason.dot_color
           is rendered directly on the element. */
    }
    .orbital-availability-select {
        background: none;
        border: 0;
        color: inherit;
        font: inherit;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        padding: 0 0.25rem;
        outline: none;
    }

    /* Card-footer action row. Used by /admin/setup cards (and any
       other section with a single trailing button) so the button
       doesn't butt up against the preceding metrics list. Border +
       padding match Filament's own card-footer convention. */
    .orbital-card-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid var(--gray-200);
    }
    .dark .orbital-card-actions {
        border-top-color: var(--gray-800);
    }

    /* Dashboard health-check card body. Keeps every card the same
       shape regardless of metric count — a one-line message with
       an optional Details button. Metrics themselves live in the
       click-to-open modal below so the grid row never has to
       stretch to accommodate the Asterisk card's five probes vs.
       a simple one-metric card. */
    .orbital-card-body {
        display: flex;
        flex-direction: column;
        min-height: 4rem;
    }
    .orbital-card-message {
        font-size: 0.875rem;
        color: var(--gray-600);
        line-height: 1.4;
        flex: 1;
    }
    .dark .orbital-card-message {
        color: var(--gray-400);
    }
    .orbital-card-details-btn {
        background: none;
        border: 0;
        padding: 0;
        margin-top: 0.75rem;
        font-size: 0.8125rem;
        font-weight: 500;
        color: var(--primary-600);
        cursor: pointer;
        align-self: flex-end;
        font-family: inherit;
    }
    .orbital-card-details-btn:hover {
        color: var(--primary-700);
        text-decoration: underline;
    }
    .dark .orbital-card-details-btn {
        color: var(--primary-400);
    }
    .dark .orbital-card-details-btn:hover {
        color: var(--primary-300);
    }

    /* Click-to-open metric detail modal. Fixed, centered, closes on
       backdrop click or Escape. Used by the dashboard health cards
       so metrics stay tucked away until an operator specifically
       wants to see them. */
    .orbital-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        z-index: 60;
    }
    .orbital-modal {
        width: 100%;
        max-width: 32rem;
        max-height: calc(100vh - 3rem);
        background: white;
        color: var(--gray-900);
        border: 1px solid var(--gray-200);
        border-radius: 0.75rem;
        box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.35);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .dark .orbital-modal {
        background: var(--gray-900);
        color: var(--gray-100);
        border-color: var(--gray-800);
    }
    .orbital-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--gray-200);
    }
    .dark .orbital-modal-header {
        border-bottom-color: var(--gray-800);
    }
    .orbital-modal-title {
        font-size: 1rem;
        font-weight: 600;
    }
    .orbital-modal-close {
        background: none;
        border: 0;
        font-size: 1.125rem;
        line-height: 1;
        color: var(--gray-500);
        cursor: pointer;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
    }
    .orbital-modal-close:hover {
        color: var(--gray-900);
        background: var(--gray-100);
    }
    .dark .orbital-modal-close:hover {
        color: var(--gray-100);
        background: var(--gray-800);
    }
    .orbital-modal-body {
        padding: 1.25rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .orbital-modal-body .orbital-card-metrics {
        margin-top: 0;
        padding-top: 0;
    }
</style>
@endverbatim

{{-- Ship the Vite bundle on every Filament page so shared JS modules
     (sip.js softphone, wavesurfer.js recording player, etc.) are
     available without pulling anything from a public CDN. Offline-
     first is a hard requirement: a site without public internet still
     needs to be able to place calls and browse the admin UI. --}}
@vite(['resources/js/app.js'])

{{-- Sidebar Status nav-item sync.

     The Dashboards → Status nav entry shows a badge reflecting
     the cached system-health state. Filament's sidebar is server-
     rendered on full page loads only, so without this script the
     badge would freeze at whatever state it was in when the user
     navigated to the page and never update until the next full
     reload. The `SystemStatusBar` Livewire component polls every
     60s + listens for `system-health-updated`, and on every
     refresh it dispatches `orbital-status-update` as a Livewire
     browser event. This listener catches it and patches the
     sidebar entry's badge text + fi-color-* class in place so all
     three surfaces (nav badge, top-of-page bar, dashboard cards)
     stay in lockstep without a reload. --}}
<script>
    (function () {
        // Only these four are valid Filament color slugs for the
        // fi-color-* class. Anything else (undefined, empty, a
        // typo) gets rejected by the patch so we can never
        // accidentally strip the badge's color and leave it in
        // the grayscale fallback state.
        var VALID_COLORS = ['success', 'warning', 'danger', 'gray'];

        function patchStatusNavItem(badge, color) {
            // Guard #1: reject malformed payloads outright. If
            // Livewire ever dispatches with missing named args
            // (happened intermittently on wire:poll re-renders),
            // we keep whatever the badge's current classes are
            // rather than stripping them and applying nothing.
            if (typeof color !== 'string' || VALID_COLORS.indexOf(color) === -1) {
                return;
            }

            // Scope the selector to sidebar items so we don't
            // accidentally grab a breadcrumb or user-menu link
            // that also points at /admin. The Status page is the
            // panel root, so its href ends with `/admin`.
            var link = document.querySelector('.fi-sidebar-item a[href$="/admin"]');
            if (!link) return;

            var container = link.closest('.fi-sidebar-item') || link.parentElement;
            if (!container) return;

            var badgeEl = container.querySelector('.fi-badge');
            var labelEl = container.querySelector('.fi-badge-label');

            // Guard #2: only write the label if the payload
            // brought one. An undefined badge text would clear
            // the span and look like a blank pill.
            if (labelEl && typeof badge === 'string' && badge.length > 0) {
                labelEl.textContent = badge;
            }

            if (badgeEl) {
                // Strip any existing fi-color-* class and apply
                // the new one. Done as two passes so we never
                // transiently have the wrong color class applied.
                VALID_COLORS.forEach(function (c) {
                    badgeEl.classList.remove('fi-color-' + c);
                });
                badgeEl.classList.add('fi-color-' + color);
            }
        }

        // Hook into Livewire's event bus once it's ready.
        // `system-health-updated` is already dispatched by the
        // Dashboard page refresh + the status bar's poll cycle;
        // we hang the nav-item update off the same event so the
        // sidebar, bar, and cards all tick together.
        document.addEventListener('livewire:init', function () {
            if (typeof window.Livewire === 'undefined') return;
            window.Livewire.on('orbital-status-update', function (params) {
                // Livewire 3 normally passes the detail object
                // directly as the first callback arg, but some
                // poll/re-render paths wrap it in an array —
                // handle both. Anything beyond that just falls
                // through the guard in patchStatusNavItem.
                var data = Array.isArray(params) ? params[0] : params;
                if (! data || typeof data !== 'object') return;
                patchStatusNavItem(data.badge, data.color);
            });
        });
    })();
</script>

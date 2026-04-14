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
</style>
@endverbatim

{{-- Ship the Vite bundle on every Filament page so shared JS modules
     (sip.js softphone, wavesurfer.js recording player, etc.) are
     available without pulling anything from a public CDN. Offline-
     first is a hard requirement: a site without public internet still
     needs to be able to place calls and browse the admin UI. --}}
@vite(['resources/js/app.js'])

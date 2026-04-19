<x-filament-panels::page>
    {{-- Auto-refresh every 5s — gives near-real-time "active calls
         remaining" during drain without Reverb complexity. Outer
         flex + gap gives each top-level section consistent
         breathing room without ad-hoc margins on each child. --}}
    <div wire:poll.5s="refreshState" style="display: flex; flex-direction: column; gap: 1.5rem;">

        {{-- Status overview --}}
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
            <x-filament::section compact>
                <div style="text-align: center; padding: 0.5rem 0;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.375rem;">Proxy Status</div>
                    <x-filament::badge :color="$healthy ? 'success' : 'danger'">
                        {{ $healthy ? 'Healthy' : 'Unreachable' }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div style="text-align: center; padding: 0.5rem 0;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.375rem;">Uptime</div>
                    <div style="font-size: 1.125rem; font-weight: 600;">{{ $uptime }}</div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div style="text-align: center; padding: 0.5rem 0;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.375rem;">Active Calls</div>
                    <div style="font-size: 1.5rem; font-weight: 700; {{ $activeDialogs > 0 ? 'color: var(--primary-500);' : 'color: var(--gray-400);' }}">
                        {{ $activeDialogs }}
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Backends --}}
        <x-filament::section heading="Backends">
            @if (empty($dispatchers))
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem 0; text-align: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 3rem; height: 3rem; color: var(--gray-400); margin-bottom: 0.75rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                    </svg>
                    <div style="font-size: 0.875rem; font-weight: 500;">
                        {{ $healthy ? 'No dispatcher entries' : 'Kamailio unreachable' }}
                    </div>
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                        {{ $healthy ? 'The dispatcher set is empty.' : 'Is the kamailio container running?' }}
                    </div>
                </div>
            @else
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    @foreach ($dispatchers as $index => $entry)
                        @php
                            // Extract the short hostname (e.g.
                            // "asterisk-2" from "sip:asterisk-2:5060")
                            // — AsteriskDrainService uses this to
                            // route to both Kamailio and HAProxy.
                            $backendHost = preg_replace('/^sip:|:\\d+$/i', '', $entry['address']) ?? $entry['address'];
                            $actionArgs = ['backend' => $backendHost];

                            $nodeActivity = $activity[$backendHost] ?? ['channels' => 0, 'registrations' => 0, 'reachable' => false];
                            // Two per-node signals:
                            //   channels      — live calls terminating on THIS Asterisk (AMI)
                            //   registrations — ps_contacts rows where reg_server matches
                            //                   THIS Asterisk's systemname (grouped Postgres
                            //                   query, not AMI — AMI would show the shared
                            //                   ARA list identically on both nodes)
                            //
                            // Drain-complete gate is channels == 0: once no calls are
                            // terminating here, the node is safe to reboot. Registrations
                            // are shown for visibility — idle operators auto-reconnect to
                            // the surviving node via HAProxy when this one goes down, so
                            // registrations aren't blocking.
                            $drainComplete = $entry['state'] === 'draining' && $nodeActivity['channels'] === 0 && $nodeActivity['reachable'];
                            $drainInProgress = $entry['state'] === 'draining' && $nodeActivity['channels'] > 0;

                            // Status label + visual weight. Drain
                            // complete reads as "Out of service" in
                            // gray because the node is deliberately
                            // offline — green would read as "good"
                            // which is the opposite of what the
                            // operator needs to see at a glance.
                            $stateLabel = match (true) {
                                $drainComplete => 'Out of service',
                                $drainInProgress => 'Draining',
                                $entry['state'] === 'disabled' => 'Disabled',
                                $entry['state'] === 'active' => 'Active',
                                default => ucfirst($entry['state']),
                            };
                            $color = match (true) {
                                $drainComplete => 'gray',
                                $drainInProgress => 'warning',
                                $entry['state'] === 'active' => 'success',
                                $entry['state'] === 'disabled' => 'danger',
                                $entry['state'] === 'draining' => 'warning',
                                default => 'gray',
                            };
                            $icon = match (true) {
                                $drainComplete => 'heroicon-o-no-symbol',
                                $drainInProgress => 'heroicon-o-pause-circle',
                                $entry['state'] === 'active' => 'heroicon-o-check-circle',
                                $entry['state'] === 'disabled' => 'heroicon-o-x-circle',
                                default => 'heroicon-o-question-mark-circle',
                            };
                        @endphp
                        {{-- Single flat row per backend — no header
                             slot, no description slot, no body split.
                             Status badge + URI + meta on the left,
                             action buttons on the right, everything
                             inside one compact Filament section so
                             it picks up the theme's card chrome. --}}
                        <x-filament::section compact>
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                                    <x-filament::badge :color="$color" :icon="$icon">
                                        {{ $stateLabel }}
                                    </x-filament::badge>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem; font-weight: 600;">
                                        {{ $entry['address'] }}
                                    </span>
                                    <span style="font-size: 0.75rem; color: var(--gray-500);">
                                        Set {{ $entry['set_id'] }} · Priority {{ $entry['priority'] }}{{ $entry['attrs'] ? ' · '.$entry['attrs'] : '' }}
                                    </span>
                                </div>

                                {{-- Per-node activity: live calls AND
                                     dynamic registrations. Calls come
                                     from AMI on this specific Asterisk;
                                     registrations come from ps_contacts
                                     grouped on reg_server = this node's
                                     systemname (so sibling nodes'
                                     registrations don't show up here). --}}
                                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <x-filament::badge
                                            color="gray"
                                            icon="heroicon-o-phone"
                                            :attributes="new \Illuminate\View\ComponentAttributeBag(['title' => 'Live channels on this Asterisk (calls terminating here)'])"
                                        >
                                            {{ $nodeActivity['channels'] }} {{ $nodeActivity['channels'] === 1 ? 'call' : 'calls' }}
                                        </x-filament::badge>
                                        <x-filament::badge
                                            color="gray"
                                            icon="heroicon-o-user"
                                            :attributes="new \Illuminate\View\ComponentAttributeBag(['title' => 'Dynamic SIP / WSS registrations whose REGISTER was handled by this Asterisk'])"
                                        >
                                            {{ $nodeActivity['registrations'] }} {{ $nodeActivity['registrations'] === 1 ? 'registration' : 'registrations' }}
                                        </x-filament::badge>
                                        @if (! $nodeActivity['reachable'])
                                            <x-filament::badge color="danger" icon="heroicon-o-exclamation-triangle">AMI offline</x-filament::badge>
                                        @endif
                                    </div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                        @if (in_array($entry['state'], ['draining', 'disabled'], true))
                                            {{ ($this->activateBackendAction)($actionArgs) }}
                                        @endif
                                        @if ($entry['state'] === 'active')
                                            {{ ($this->drainBackendAction)($actionArgs) }}
                                        @endif
                                        @if (in_array($entry['state'], ['active', 'draining'], true))
                                            {{ ($this->disableBackendAction)($actionArgs) }}
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if ($drainComplete)
                                <div style="margin-top: 0.5rem; font-size: 0.8125rem; color: var(--gray-500);">
                                    Safe to reboot.
                                </div>
                            @elseif ($drainInProgress)
                                <div style="margin-top: 0.5rem; font-size: 0.8125rem; color: var(--warning-600);">
                                    Waiting for {{ $nodeActivity['channels'] }} call{{ $nodeActivity['channels'] === 1 ? '' : 's' }} to end.
                                </div>
                            @endif
                        </x-filament::section>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- =========================================================
             What drain actually does — scope explainer.

             Drain now hits TWO control planes at once: Kamailio
             (inbound SIP trunks) and HAProxy (operator softphone
             WSS). A drained Asterisk receives zero new traffic on
             either path; existing calls finish naturally and the
             AMI-reported channel count on the card above ticks
             down to zero, at which point it's safe to reboot.
             ========================================================= --}}
        <x-filament::section
            icon="heroicon-o-information-circle"
            icon-color="primary"
            heading="What drain actually affects"
            description="Drain blocks new traffic on both SIP trunk and softphone paths. Existing calls complete naturally; a live channel counter tells you when the node is safe to reboot."
            collapsible
            collapsed
        >
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.5rem; color: var(--warning-600);">
                        Blocked by drain (new traffic only)
                    </div>
                    <ul style="font-size: 0.8125rem; line-height: 1.5; list-style: disc; padding-left: 1.25rem; margin: 0;">
                        <li><strong>Inbound SIP trunk calls</strong> from PSTN providers (5060 UDP/TCP / 5061 TLS) — Kamailio dispatcher marks the backend inactive</li>
                        <li><strong>Hardware TAs / SIP phones</strong> that register via classic SIP through the Kamailio front door</li>
                        <li><strong>Operator softphone WSS connections</strong> (:8089) — HAProxy marks the backend <code>MAINT</code>, source-hash balancing sends new browser sessions to the surviving Asterisk</li>
                        <li><strong>New LiveKit SIP bridge calls</strong> — indirectly, since Asterisk dials the bridge only when terminating a fresh call, and new calls don't land here</li>
                    </ul>
                </div>
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.5rem; color: var(--success-600);">
                        Not affected by drain
                    </div>
                    <ul style="font-size: 0.8125rem; line-height: 1.5; list-style: disc; padding-left: 1.25rem; margin: 0;">
                        <li><strong>Calls already in progress</strong> on the drained node — SIP signaling and RTP media continue on the existing legs until the participants hang up</li>
                        <li><strong>Operator WSS sessions already open</strong> — your browser stays connected to the Asterisk it was on; the drain doesn't forcibly disconnect you</li>
                        <li><strong>RTP media</strong> <code>10000-10099/udp</code> — peer-to-peer between endpoint and the terminating Asterisk, not proxy-routable</li>
                        <li><strong>Outbound trunk registrations</strong> — Asterisk talks to your SIP provider directly, no Kamailio or HAProxy in the path</li>
                    </ul>
                </div>
            </div>

            <div style="margin-top: 1rem; padding-top: 1rem; font-size: 0.8125rem; line-height: 1.5; color: var(--gray-600);" class="dark:!text-gray-300">
                <strong>Maintenance runbook:</strong>
                <ol style="list-style: decimal; padding-left: 1.25rem; margin: 0.5rem 0 0;">
                    <li>Click <strong>Drain</strong> on the Asterisk you want to service. Kamailio and HAProxy both pull it from rotation immediately.</li>
                    <li>Every logged-in operator sees a maintenance banner with instructions: <em>finish your current call, go unavailable, log out and log back in</em>. Logging back in reconnects through HAProxy to the surviving Asterisk.</li>
                    <li>Watch the <em>N calls</em> counter on this backend's card &mdash; it's a live AMI read of channels terminating on this specific Asterisk. Ticks down as customers hang up.</li>
                    <li>When the counter hits zero, the card flips to <em>Out of service</em>. Any operators still connected but not on a call auto-reconnect to the surviving node when this Asterisk goes down.</li>
                    <li>Reboot / upgrade / redeploy the Asterisk container with confidence — no customer-impacting traffic was on it.</li>
                    <li>Click <strong>Activate</strong> to put it back in rotation on both planes. New calls and new softphone registrations start landing again via normal source-hash balancing.</li>
                </ol>
                <p style="margin: 0.75rem 0 0;">
                    <strong>Why channels, not contacts?</strong> Registered contacts live in shared ARA tables, so both Asterisks always report the same list regardless of which one is actually serving a given softphone. Only the live-channel count is node-specific, so that's the drain-complete signal.
                </p>
                <p style="margin: 0.75rem 0 0;">
                    <strong>Why registrations, not just channels?</strong> Registrations tell you how many softphones are still pinned to a drained node — they won't cause a data-loss problem when the Asterisk reboots (HAProxy reconnects them to the surviving node), but they're a useful maintenance signal: <em>N operators are still logged in here, and they'll see a brief reconnect blip when I restart</em>. Channels is the blocking signal (active calls drop on reboot); registrations is informational.
                </p>
            </div>
        </x-filament::section>
    </div>

</x-filament-panels::page>

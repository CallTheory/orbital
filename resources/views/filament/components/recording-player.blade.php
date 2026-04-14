@php
    /** @var \App\Models\CallLog $call */
    // Works both inside a Filament infolist (where $getRecord() is
    // injected) and as a plain partial passed a $record variable.
    $call = $record ?? (isset($getRecord) ? $getRecord() : null);

    $disk = (string) config('telephony.recording.storage_disk', 's3');

    // Generate short-lived pre-signed URLs for each leg. Recordings
    // stay in a private bucket; this is how the browser gets
    // temporary read access without exposing S3 credentials.
    $urls = [];
    foreach (['mix' => 'recording_path', 'rx' => 'recording_rx_path', 'tx' => 'recording_tx_path'] as $leg => $column) {
        $path = $call->{$column};
        if (! $path) {
            continue;
        }
        try {
            $urls[$leg] = \Illuminate\Support\Facades\Storage::disk($disk)
                ->temporaryUrl($path, now()->addMinutes(15));
        } catch (\Throwable $e) {
            // Fall back to a plain disk URL if the driver doesn't
            // support temporaryUrl (e.g. local disk in dev).
            $urls[$leg] = \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
        }
    }
@endphp

@if (empty($urls))
    <p class="text-sm text-gray-500 dark:text-gray-400">
        No recording available for this call.
    </p>
@else
    <div
        x-data="orbitalRecordingPlayer({
            urls: @js($urls),
            peakColor: 'rgb(99 102 241)',
            progressColor: 'rgb(67 56 202)',
        })"
        x-init="init()"
        wire:ignore
        class="space-y-3"
    >
        {{-- Leg tabs --}}
        <div class="flex items-center gap-2">
            @foreach ([
                'mix' => 'Full conversation',
                'rx' => 'Caller',
                'tx' => 'Agent',
            ] as $leg => $label)
                @if (isset($urls[$leg]))
                    <button
                        type="button"
                        @click="switchLeg('{{ $leg }}')"
                        :class="activeLeg === '{{ $leg }}'
                            ? 'bg-indigo-600 text-white'
                            : 'bg-white/5 text-gray-400 hover:bg-white/10'"
                        class="rounded-full px-3 py-1 text-xs font-medium transition"
                    >
                        {{ $label }}
                    </button>
                @endif
            @endforeach
        </div>

        {{-- Waveform canvas --}}
        <div
            x-ref="waveform"
            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-black/30"
            style="min-height: 96px;"
        ></div>

        {{-- Transport controls --}}
        <div class="flex items-center gap-3 text-sm">
            <button
                type="button"
                @click="toggle()"
                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
            >
                <span x-show="!playing">▶ Play</span>
                <span x-show="playing" x-cloak>⏸ Pause</span>
            </button>
            <span x-text="currentLabel" class="font-mono text-xs text-gray-500"></span>
            <span class="font-mono text-xs text-gray-400">/</span>
            <span x-text="durationLabel" class="font-mono text-xs text-gray-400"></span>
            <div class="ml-auto">
                <a
                    :href="urls[activeLeg]"
                    download
                    class="text-xs text-indigo-600 hover:text-indigo-500"
                >
                    Download
                </a>
            </div>
        </div>
    </div>

    @once
        {{-- WaveSurfer.js is bundled via resources/js/app.js (Vite) and
             exposed as window.WaveSurfer. No CDN — the admin UI has to
             function with no public-internet access. --}}
        <script>
            function orbitalRecordingPlayer(config) {
                return {
                    urls: config.urls,
                    peakColor: config.peakColor,
                    progressColor: config.progressColor,
                    activeLeg: Object.keys(config.urls)[0],
                    playing: false,
                    current: 0,
                    duration: 0,
                    ws: null,

                    init() {
                        this.mount();
                    },

                    mount() {
                        // Wait for the Vite bundle to finish evaluating.
                        if (!window.WaveSurfer) {
                            setTimeout(() => this.mount(), 50);
                            return;
                        }
                        if (this.ws) {
                            this.ws.destroy();
                        }
                        this.ws = window.WaveSurfer.create({
                            container: this.$refs.waveform,
                            waveColor: this.peakColor,
                            progressColor: this.progressColor,
                            cursorColor: 'rgb(156 163 175)',
                            barWidth: 2,
                            barGap: 1,
                            barRadius: 2,
                            height: 72,
                            normalize: true,
                            url: this.urls[this.activeLeg],
                        });
                        this.ws.on('ready', () => {
                            this.duration = this.ws.getDuration();
                        });
                        this.ws.on('audioprocess', () => {
                            this.current = this.ws.getCurrentTime();
                        });
                        this.ws.on('play', () => { this.playing = true; });
                        this.ws.on('pause', () => { this.playing = false; });
                        this.ws.on('finish', () => { this.playing = false; });
                    },

                    switchLeg(leg) {
                        if (! this.urls[leg] || leg === this.activeLeg) return;
                        this.activeLeg = leg;
                        this.playing = false;
                        this.current = 0;
                        this.duration = 0;
                        this.mount();
                    },

                    toggle() {
                        if (! this.ws) return;
                        this.ws.playPause();
                    },

                    get currentLabel() {
                        return this.format(this.current);
                    },
                    get durationLabel() {
                        return this.format(this.duration);
                    },
                    format(s) {
                        if (!isFinite(s)) return '0:00';
                        const m = Math.floor(s / 60);
                        const r = Math.floor(s % 60).toString().padStart(2, '0');
                        return `${m}:${r}`;
                    },
                };
            }
        </script>
    @endonce
@endif

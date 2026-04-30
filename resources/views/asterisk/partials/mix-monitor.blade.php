{{--
    Recording is no longer Asterisk's responsibility — rtpengine at
    the Kamailio edge owns it now (per docs/plans/rtpengine-edge.md
    Phase 1a). Every SIP dialog that traverses the platform crosses
    the edge, where rtpengine's recording-daemon writes paired
    `*-recv.wav` / `*-send.wav` files keyed by SIP Call-ID. The
    `orbital:upload-recordings` watcher drains those into
    `call_recordings` rows.

    This partial is intentionally inert. It stays in place so the
    `@include` calls in extensions.blade.php and client-dialplan.blade.php
    don't break — Filament's AsteriskConfigService still renders both,
    and we'd rather not surgically remove the includes (cleaner diff
    when the legacy MixMonitor path is needed for comparison + easier
    revert if Phase 1a needs to roll back).

    The `recording_policy`-driven beep / disclosure prompt overlays
    that this partial used to emit will be re-implemented over rtpengine's
    `play-media` NG command in a follow-up; for now beep + disclosure
    are silently dropped at the dialplan layer.

    Expected variables in scope:
      $ext — App\Models\Extension with `recording_policy` set by
             AsteriskConfigService before the Blade renders.
--}}
@php
    /** @var \App\Services\Telephony\CallRecordingPolicy|null $policy */
    $policy = $ext->getAttribute('recording_policy');
    $shouldRecord = $policy && $policy->enabled;
@endphp
@if($shouldRecord)
; recording handled by rtpengine at the edge ({{ $policy->source }})
 same => n,NoOp(Recording owned by rtpengine — see Failover Central)
@endif

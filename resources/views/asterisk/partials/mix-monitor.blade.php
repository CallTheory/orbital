{{--
    Dual-channel call recording block for an extension's dialplan.

    Emits three MixMonitor output files per call:

      - Combined mix (the whole conversation, useful for playback)
      - rx leg   (receive / caller audio only)
      - tx leg   (transmit / agent audio only)

    The rx/tx legs let the transcription pipeline assign speaker
    labels without running speaker diarization — you already know
    which side is which because they're separate files. Big win for
    accuracy and cost.

    Output directory convention:
        /var/spool/asterisk/monitor/tenants/{team_id}/{YYYY}/{MM}/
    ...and the per-call filename is the call's unique_id (UNIQUEID
    at dialplan evaluation time). A companion uploader job watches
    that directory and moves completed files to MinIO under the
    orbital-recordings bucket.

    Optional legal-compliance overlays (all driven by the resolved
    CallRecordingPolicy, never hard-coded here):

      - `b` flag on MixMonitor (start-of-call beep), when
        `$policy->beepOnRecord` is true.
      - Playback() of a pre-rendered TTS disclosure file, when
        `$policy->disclosurePromptPath` is non-null. Rendered by
        App\Services\Telephony\DisclosureRenderer into the shared
        asterisk-prompts volume.
      - PERIODIC_HOOK registering a recurring beep on the active
        channel every `$policy->beepIntervalSeconds` seconds, when
        that value is greater than zero. Hook target lives in the
        baked [hooks-beep] context.

    When $ext->recording_policy->enabled is false, nothing is emitted —
    `Dial()` runs without any monitor attached.

    Expected variables in scope:
      $ext — App\Models\Extension with `recording_policy` set by
             AsteriskConfigService before the Blade renders.
--}}
@php
    /** @var \App\Services\Telephony\CallRecordingPolicy|null $policy */
    $policy = $ext->getAttribute('recording_policy');
    $shouldRecord = $policy && $policy->enabled;
    $teamId = (int) ($ext->team_id ?? 0);
    $format = $policy?->format ?? 'wav';
    // Asterisk variable expansion — ${UNIQUEID} gets replaced at runtime
    // with the per-call unique id. We build the directory path at
    // generation time and embed the UNIQUEID ref verbatim.
    $dir = "/var/spool/asterisk/monitor/tenants/{$teamId}/\${STRFTIME(\${EPOCH},,%Y/%m)}";
    $mixFile = $dir.'/${UNIQUEID}-mix.'.$format;
    $rxFile = $dir.'/${UNIQUEID}-rx.'.$format;
    $txFile = $dir.'/${UNIQUEID}-tx.'.$format;
    $beep = $policy?->beepOnRecord ? 'b' : '';
    $disclosurePath = $policy?->disclosurePromptPath;
    $beepInterval = (int) ($policy?->beepIntervalSeconds ?? 0);
@endphp
@if($shouldRecord)
 same => n,NoOp(Recording enabled via {{ $policy->source }}; mix + rx + tx)
 same => n,MkDir({{ $dir }})
 same => n,MixMonitor({{ $mixFile }},{{ $beep }}r({{ $rxFile }})t({{ $txFile }}))
@if($disclosurePath)
 same => n,NoOp(Playing recording disclosure to caller)
 same => n,Playback({{ $disclosurePath }})
@endif
@if($beepInterval > 0)
 same => n,NoOp(Registering periodic recording beep every {{ $beepInterval }}s)
 same => n,Set(RECORDING_BEEP_HOOK=${PERIODIC_HOOK(hooks-beep,beep,{{ $beepInterval }})})
@endif
@endif

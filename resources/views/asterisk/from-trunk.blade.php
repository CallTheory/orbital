{{--
    Inbound trunk dispatcher. One file at
    /etc/asterisk/generated/from-trunk.conf, #include'd from
    generated/dialplan_index.conf alongside the per-tenant
    dialplans.

    Every inbound call from a SipTrunk lands in [from-trunk] and we
    pattern-match the called DID against routing rules to decide
    which tenant context to Goto into. The actual extensions /
    queues / IVR live inside the tenant context (see
    tenant-dialplan.blade.php) — this file is purely a router.

    Variables in scope:
        $rules — collection of active RoutingRule rows, eager-loaded
                 with `team` and the destination relation, ordered by
                 priority ASC then DID specificity (most specific
                 first so a wildcard never shadows a named DID).
--}}
;===============================================================================
; from-trunk dispatcher
; Generated: {{ now()->toIso8601String() }}
; DO NOT EDIT — regenerated when a RoutingRule, DID, or tenant changes
;===============================================================================

[from-trunk]

@foreach($rules as $rule)
@php
    $tenantContext = $rule->team?->dialplanContext() ?? 'internal';
    $pattern = $rule->match_pattern ?: '_X.';
@endphp
; Rule {{ $rule->id }}: {{ $rule->name ?? '(unnamed)' }} → {{ $tenantContext }}
exten => {{ $pattern }},1,NoOp(Inbound {{ $pattern }} for tenant {{ $rule->team_id ?? 'platform' }} via rule {{ $rule->id }})
@switch($rule->destination_type)
@case('extension')
 same => n,Goto({{ $tenantContext }},{{ $rule->destination_id }},1)
@break
@case('queue')
 same => n,Goto({{ $tenantContext }},queue-{{ $rule->destination_id }},1)
@break
@case('voicemail')
@php
    // Per-tenant custom TTS greeting: when the tenant picked
    // `custom_tts` on their admin UI AND the renderer produced
    // a WAV at /var/spool/asterisk/prompts/voicemail-greetings/{id}.wav,
    // Playback() the custom file and pass `s` to VoiceMail so
    // Asterisk skips its own "please leave a message" intro. If
    // the tenant stayed on `asterisk_default` or the render
    // failed, fall back to the stock VoiceMail() path.
    $customGreeting = null;
    if ($rule->team && $rule->team->voicemail_greeting_mode === 'custom_tts') {
        $renderer = app(\App\Services\Telephony\VoicemailGreetingRenderer::class);
        if ($renderer->hasGreeting($rule->team)) {
            $customGreeting = $renderer->asteriskPromptPath($rule->team);
        }
    }
@endphp
 same => n,Answer()
@if($customGreeting)
 same => n,Playback({{ $customGreeting }})
 same => n,VoiceMail({{ $rule->destination_id }}@@default,s)
@else
 same => n,VoiceMail({{ $rule->destination_id }}@@default)
@endif
@break
@default
 same => n,Goto({{ $tenantContext }},{{ $rule->destination_id }},1)
@endswitch
 same => n,Hangup()

@endforeach

; Catch-all — any DID with no matching rule gets a clean hangup so
; SIP scanners can't trigger billing-loud loops.
exten => _X.,1,NoOp(Unmatched inbound DID ${EXTEN})
 same => n,Hangup()


[from-livekit]

; Calls returning from the LiveKit AI agent leg dial back here with
; the original tenant extension as ${EXTEN}. Until Phase 4 lands the
; ARA endpoint naming (t{team_id}_{number}), this stays a flat
; fallback — when a LiveKit return needs to land in a per-tenant
; context the worker should send the prefixed name.
exten => _X.,1,NoOp(From LiveKit: ${EXTEN})
 same => n,Goto(default,${EXTEN},1)

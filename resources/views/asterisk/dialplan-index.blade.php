{{--
    The dialplan index file. Sits at
    /etc/asterisk/generated/dialplan_index.conf and is the single
    entry point #tryinclude'd from the baked extensions.conf.

    Asterisk's `#include` directive doesn't support globbing, so
    this file's only job is to enumerate every per-client dialplan
    file (and the from-trunk dispatcher) by name. Regenerated when
    clients are created or deleted; client-internal edits don't
    touch it.

    Variables in scope:
        $teamIds — array of integers, the clients whose dialplan
                   files exist on disk under generated/clients/
--}}
;===============================================================================
; dialplan index
; Generated: {{ now()->toIso8601String() }}
; DO NOT EDIT — regenerated when clients are created/deleted
;===============================================================================

#include "generated/from-trunk.conf"
#include "generated/extensions_generated.conf"

@foreach($teamIds as $teamId)
#include "generated/clients/{{ $teamId }}-dialplan.conf"
@endforeach

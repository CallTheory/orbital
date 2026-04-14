{{--
    The dialplan index file. Sits at
    /etc/asterisk/generated/dialplan_index.conf and is the single
    entry point #tryinclude'd from the baked extensions.conf.

    Asterisk's `#include` directive doesn't support globbing, so
    this file's only job is to enumerate every per-tenant dialplan
    file (and the from-trunk dispatcher) by name. Regenerated when
    tenants are created or deleted; tenant-internal edits don't
    touch it.

    Variables in scope:
        $teamIds — array of integers, the tenants whose dialplan
                   files exist on disk under generated/tenants/
--}}
;===============================================================================
; dialplan index
; Generated: {{ now()->toIso8601String() }}
; DO NOT EDIT — regenerated when tenants are created/deleted
;===============================================================================

#include "from-trunk.conf"

@foreach($teamIds as $teamId)
#include "tenants/{{ $teamId }}-dialplan.conf"
@endforeach

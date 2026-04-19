<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Public `system-health` channel — carries operational status for
// every Filament panel tab. No auth callback because it's public;
// the payload is platform-wide health state, not tenant data.
// Define the channel explicitly anyway so it shows up in route:list
// and anyone grepping for its name finds a canonical definition.
Broadcast::channel('system-health', fn () => true);

// Per-user operator drain channel — carries the "your Asterisk is
// draining, finish your call and refresh" nudge. Authenticated user
// must match the ID in the channel name.
Broadcast::channel('operator.drain.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

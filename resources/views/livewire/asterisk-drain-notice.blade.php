{{-- Invisible. This component exists only to receive the Reverb
     `asterisk-drain-initiated` event and fire a Filament toast
     via Notification::make()->persistent()->send(). The toast
     itself renders in the panel's standard notification stack. --}}
<div></div>

@php
    $u = parse_url((string) config('app.url'));
    $scheme = $u['scheme'] ?? 'https';
    $host = $u['host'] ?? 'localhost';
    $port = $u['port'] ?? ($scheme === 'https' ? 443 : 80);
@endphp
<script>
window.__ORBITAL_ECHO__ = {
    key: @json(config('broadcasting.connections.reverb.key')),
    wsHost: @json($host),
    wsPort: {{ (int) $port }},
    forceTLS: {{ $scheme === 'https' ? 'true' : 'false' }},
};
</script>

{{--
    AGPL section 13 source offer.

    Rendered into the FOOTER hook of all three panels — admin, operator,
    AND portal. The portal one matters most: a client user interacting with
    a modified Orbital over the network is exactly who section 13 is written
    to protect, and they never see the admin panel's About page.

    Keep this cheap and unauthenticated-safe. It reads config only, touches
    no database, and must render even when the rest of the app is unhappy.
--}}
@php
    $release = \App\Support\Release::class;
@endphp

<div style="padding: 0.75rem 1rem 1.25rem; text-align: center; font-size: 0.6875rem; color: var(--gray-400); line-height: 1.6;">
    <span>{{ config('orbital.platform_name', 'Orbital') === 'Orbital' ? 'Orbital' : 'Powered by Orbital' }}</span>
    <span aria-hidden="true">&middot;</span>
    <span>{{ $release::display() }}</span>
    <span aria-hidden="true">&middot;</span>
    <a href="{{ $release::licenseUrl() }}" target="_blank" rel="noopener" style="text-decoration: underline;">{{ $release::licenseSpdx() }}</a>
    <span aria-hidden="true">&middot;</span>
    <a href="{{ route('source') }}" target="_blank" rel="noopener" style="text-decoration: underline;">Source</a>
</div>

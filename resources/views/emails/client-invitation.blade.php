@php
    $inviterLine = $inviter?->name
        ? "{$inviter->name} has invited you"
        : "You've been invited";
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Invitation to {{ $client->name }}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1f2937; line-height: 1.6; max-width: 560px; margin: 0 auto; padding: 32px;">
    <h2 style="color: #111827; margin: 0 0 16px;">You've been invited to {{ $client->name }}</h2>

    <p style="margin: 0 0 16px;">
        {{ $inviterLine }} to join <strong>{{ $client->name }}</strong> on Orbital.
    </p>

    <p style="margin: 0 0 24px;">
        Click the button below to accept. If you don't have an Orbital
        account yet, we'll create one for you in the same step —
        there's no separate signup.
    </p>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $acceptUrl }}" style="display: inline-block; background: #2563eb; color: #fff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600;">
            Accept invitation
        </a>
    </p>

    <p style="font-size: 13px; color: #6b7280; margin-top: 24px;">
        Or copy this link into your browser:<br>
        <a href="{{ $acceptUrl }}" style="color: #2563eb; word-break: break-all;">{{ $acceptUrl }}</a>
    </p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
    <p style="font-size: 12px; color: #9ca3af;">
        This invitation expires {{ $invitation->expires_at?->toFormattedDateString() ?? 'soon' }}.
        If you weren't expecting this email, you can safely ignore it.
    </p>
</body>
</html>

@php
    /** @var \App\Models\ClientInvitation $invitation */
    /** @var ?\App\Models\User $authed */
    /** @var bool $emailMatches */
    /** @var bool $userExists */
    $inviterName = $invitation->invitedBy?->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invitation — {{ $invitation->team->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f9fafb; margin: 0; padding: 32px 16px; color: #1f2937; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 32px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        h1 { color: #111827; font-size: 1.5rem; margin: 0 0 0.5rem; }
        .subtitle { color: #6b7280; font-size: 0.95rem; margin-bottom: 1.5rem; }
        label { display: block; font-size: 0.875rem; font-weight: 500; margin-top: 1rem; margin-bottom: 0.25rem; color: #374151; }
        input { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; box-sizing: border-box; }
        input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
        input[disabled] { background: #f3f4f6; color: #6b7280; }
        button { margin-top: 1.5rem; width: 100%; padding: 0.75rem; background: #2563eb; color: #fff; border: 0; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .error { margin-top: 1rem; padding: 0.75rem 1rem; background: #fef2f2; border-left: 4px solid #ef4444; color: #7f1d1d; font-size: 0.875rem; }
        .notice { margin-top: 1rem; padding: 0.75rem 1rem; background: #eff6ff; border-left: 4px solid #3b82f6; color: #1e3a8a; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Join {{ $invitation->team->name }}</h1>
        <p class="subtitle">
            @if($inviterName)
                {{ $inviterName }} invited <strong>{{ $invitation->email }}</strong>.
            @else
                This invitation is for <strong>{{ $invitation->email }}</strong>.
            @endif
        </p>

        @if($errors->any())
            <div class="error">
                @foreach($errors->all() as $error){{ $error }}@endforeach
            </div>
        @endif

        @if($authed && ! $emailMatches)
            {{-- Signed in as somebody else — force a sign-out step so
                 we don't bind the invitation to the wrong account. --}}
            <div class="notice">
                You're signed in as <strong>{{ $authed->email }}</strong>. To accept this invitation for
                <strong>{{ $invitation->email }}</strong>, sign out first, then click the email link again.
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        @elseif($authed && $emailMatches)
            {{-- Already signed in as the right user — one-click accept. --}}
            <form method="POST" action="{{ route('invitation.accept', ['token' => $invitation->token]) }}">
                @csrf
                <button type="submit">Accept invitation</button>
            </form>
        @elseif($userExists)
            {{-- Email already has an Orbital account — ask for password. --}}
            <form method="POST" action="{{ route('invitation.accept', ['token' => $invitation->token]) }}">
                @csrf
                <label>Email</label>
                <input type="email" value="{{ $invitation->email }}" disabled>
                <label>Password</label>
                <input type="password" name="password" required autofocus>
                <button type="submit">Sign in &amp; accept</button>
            </form>
        @else
            {{-- Brand-new user — single form creates the account and
                 accepts in one submit. No "create account first, come
                 back to the email" round-trip. --}}
            <form method="POST" action="{{ route('invitation.accept', ['token' => $invitation->token]) }}">
                @csrf
                <label>Email</label>
                <input type="email" value="{{ $invitation->email }}" disabled>
                <label>Your name</label>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus>
                <label>Set a password</label>
                <input type="password" name="password" required minlength="8">
                <label>Confirm password</label>
                <input type="password" name="password_confirmation" required>
                <button type="submit">Create account &amp; accept</button>
            </form>
        @endif
    </div>
</body>
</html>

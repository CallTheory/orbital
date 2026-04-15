{{--
    Passport OAuth2 consent view stub.

    Required only because Passport 13 type-hints AuthorizationViewResponse
    as a method parameter on AuthorizationController, so Laravel needs SOME
    view bound to the contract even though our first-party clients
    (pgAdmin, MinIO Console, registered in SsoSecretsBootstrapper) skip
    the consent step via FirstPartyClient::skipsAuthorization().

    If you land on this page in a browser, it means a client hit
    /oauth/authorize that wasn't marked as first-party. Either add it
    to FirstPartyClient::FIRST_PARTY_NAMES or build a real consent
    screen here following Passport's stock shape.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Authorize — {{ config('app.name') }}</title>
</head>
<body style="font-family:system-ui,sans-serif;max-width:480px;margin:4rem auto;padding:1rem;">
    <h1>Authorization required</h1>
    <p>
        <strong>{{ $client->name ?? 'An application' }}</strong> is asking permission
        to access your {{ config('app.name') }} account.
    </p>

    <p>
        This page is the fallback consent screen for OAuth2 clients that
        haven't been registered as first-party in
        <code>App\Models\Passport\FirstPartyClient</code>. If you landed
        here unexpectedly, the client probably needs to be added to that
        allowlist so the consent step is skipped.
    </p>

    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="state" value="{{ $request->input('state') }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">Authorize</button>
    </form>

    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf
        <input type="hidden" name="_method" value="DELETE">
        <input type="hidden" name="state" value="{{ $request->input('state') }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">Cancel</button>
    </form>
</body>
</html>

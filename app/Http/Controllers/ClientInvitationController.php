<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Public (unauthenticated) invitation acceptance flow.
 *
 * One token, four states, one clean handoff — matches the Google-
 * Doc-invite UX the user asked for: if the invitee has an Orbital
 * account the link signs them in and accepts; if they don't, the
 * same page creates the account AND accepts in one submit.
 */
class ClientInvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = $this->resolveInvitation($token);
        $authed = Auth::user();

        return view('invitations.accept', [
            'invitation' => $invitation,
            'authed' => $authed,
            'emailMatches' => $authed !== null && strtolower($authed->email) === strtolower($invitation->email),
            'userExists' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    /**
     * Accept — branches on the caller's auth state. Non-
     * authenticated requests must include name + password when the
     * email doesn't yet have a User; authenticated requests just
     * confirm the email match.
     */
    public function accept(Request $request, string $token)
    {
        $invitation = $this->resolveInvitation($token);

        $authed = Auth::user();
        if ($authed && strtolower($authed->email) !== strtolower($invitation->email)) {
            // Signed in as somebody else — send the form back with
            // an explicit sign-out prompt rather than silently
            // binding the wrong user.
            return back()->withErrors([
                'auth' => "You're signed in as {$authed->email}. Sign out, then accept as {$invitation->email}.",
            ]);
        }

        if ($authed) {
            $user = $authed;
        } else {
            $existing = User::where('email', $invitation->email)->first();
            if ($existing) {
                // Existing account — one field (password). Attempt
                // login, attach role on success.
                $data = $request->validate([
                    'password' => 'required|string',
                ]);
                if (! Auth::attempt(['email' => $invitation->email, 'password' => $data['password']])) {
                    return back()->withErrors(['password' => 'Password doesn\'t match our records.']);
                }
                $user = Auth::user();
            } else {
                // Brand-new user — create the account from the
                // submitted name + password, log them in, attach role.
                $data = $request->validate([
                    'name' => 'required|string|max:255',
                    'password' => 'required|string|min:8|confirmed',
                ]);
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => Hash::make($data['password']),
                    'email_verified_at' => now(),
                ]);
                Auth::login($user);
            }
        }

        $this->grantRoleAndMembership($user, $invitation);

        $invitation->forceFill(['accepted_at' => now()])->save();

        return redirect()->to('/portal')
            ->with('invitation.accepted', $invitation->team->name);
    }

    /**
     * Pull the ClientInvitation row and bail with a clear page if
     * the token is bogus / expired / already used. Keeps the
     * `show` and `accept` handlers free of four-way branching.
     */
    protected function resolveInvitation(string $token): ClientInvitation
    {
        $invitation = ClientInvitation::query()
            ->with('team', 'invitedBy')
            ->where('token', $token)
            ->first();

        abort_if($invitation === null, 404, 'Invitation not found.');
        abort_if($invitation->isAccepted(), 410, 'This invitation has already been accepted.');
        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');

        return $invitation;
    }

    /**
     * Grant the invitation's tenant-scoped role and attach the user
     * to the team via Jetstream's team_user pivot.
     */
    protected function grantRoleAndMembership(User $user, ClientInvitation $invitation): void
    {
        $team = $invitation->team;

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);
        try {
            $role = Role::where('team_id', $team->id)
                ->where('name', 'client_user')
                ->first();
            if ($role) {
                $user->assignRole($role);
            }
            $team->users()->syncWithoutDetaching([$user->id => ['role' => 'member']]);
            $user->forceFill(['current_team_id' => $team->id])->save();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }
}

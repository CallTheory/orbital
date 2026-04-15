<x-filament-panels::page>
    @php $tfa = $this->getTwoFactorState(); @endphp

    <div class="orbital-section-stack">
        {{-- ── Two-factor authentication ─────────────────────────── --}}
        <x-filament::section
            icon="heroicon-o-key"
            icon-color="primary"
        >
            <x-slot name="heading">Two-factor authentication</x-slot>
            <x-slot name="description">
                Add a second factor (authenticator app) to your account so a
                stolen password isn't enough to sign in.
            </x-slot>

            <x-slot name="headerEnd">
                @if ($tfa['confirmed'])
                    <x-filament::badge color="success" icon="heroicon-m-check-circle">
                        Enabled
                    </x-filament::badge>
                @elseif ($tfa['enabled'])
                    <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
                        Pending confirmation
                    </x-filament::badge>
                @else
                    <x-filament::badge color="gray">
                        Not enabled
                    </x-filament::badge>
                @endif
            </x-slot>

            @if (! $tfa['enabled'])
                <p class="orbital-text-muted">
                    You have not enabled two-factor authentication. When enabled,
                    you'll be prompted for a secure random token during sign-in.
                    You can retrieve this token from any authenticator app
                    (Google Authenticator, 1Password, Bitwarden, Authy, etc.).
                </p>

                <div class="orbital-form-actions">
                    <x-filament::button
                        wire:click="enableTwoFactor"
                        icon="heroicon-m-plus"
                    >
                        Enable
                    </x-filament::button>
                </div>
            @elseif (! $tfa['confirmed'])
                {{-- Enabled but not yet confirmed: show QR + 6-digit input --}}
                <div class="orbital-form-stack">
                    <p class="orbital-text-muted">
                        Scan the QR code below with your authenticator app, then enter
                        the 6-digit code it generates to finish enabling two-factor
                        authentication.
                    </p>

                    <div style="display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
                        <div style="background: white; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid rgb(229 231 235);">
                            {!! $tfa['qr'] !!}
                        </div>
                        <div style="flex: 1; min-width: 16rem;">
                            <div style="font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: rgb(107 114 128);">
                                Setup key
                            </div>
                            <div style="margin-top: 0.25rem; word-break: break-all; font-family: ui-monospace, monospace; font-size: 0.875rem;">
                                {{ $tfa['secret_key'] }}
                            </div>
                            <p class="orbital-text-muted" style="margin-top: 0.5rem;">
                                Use this if your app can't scan QR codes.
                            </p>
                        </div>
                    </div>

                    <form wire:submit="confirmTwoFactor" class="orbital-form-stack">
                        <div class="orbital-form-field">
                            <label>Authenticator code</label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    wire:model="confirmationCode"
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="6"
                                    autocomplete="one-time-code"
                                    placeholder="123456"
                                    required
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="orbital-form-actions">
                            <x-filament::button
                                type="button"
                                color="gray"
                                wire:click="disableTwoFactor"
                            >
                                Cancel
                            </x-filament::button>
                            <x-filament::button type="submit">
                                Confirm
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            @else
                {{-- Fully enabled + confirmed --}}
                <div class="orbital-form-stack">
                    <p class="orbital-text-muted">
                        Two-factor authentication is active on this account. Store
                        your recovery codes somewhere safe — each one works exactly
                        once and is the only way back in if you lose access to your
                        authenticator app.
                    </p>

                    @if ($showingRecoveryCodes && ! empty($tfa['recovery_codes']))
                        <div style="background: rgb(249 250 251); padding: 1rem; border-radius: 0.5rem; border: 1px solid rgb(229 231 235);">
                            <div style="font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: rgb(107 114 128);">
                                Recovery codes
                            </div>
                            <div style="margin-top: 0.5rem; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; font-family: ui-monospace, monospace; font-size: 0.875rem;">
                                @foreach ($tfa['recovery_codes'] as $code)
                                    <div>{{ $code }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="orbital-form-actions">
                        @if (! $showingRecoveryCodes)
                            <x-filament::button
                                color="gray"
                                wire:click="$set('showingRecoveryCodes', true)"
                            >
                                Show recovery codes
                            </x-filament::button>
                        @endif
                        <x-filament::button
                            color="gray"
                            wire:click="regenerateRecoveryCodes"
                            icon="heroicon-m-arrow-path"
                        >
                            Regenerate codes
                        </x-filament::button>
                        <x-filament::button
                            color="danger"
                            wire:click="disableTwoFactor"
                            icon="heroicon-m-x-mark"
                        >
                            Disable
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </x-filament::section>

        {{-- ── Password change ───────────────────────────────────── --}}
        <x-filament::section
            icon="heroicon-o-lock-closed"
            icon-color="primary"
        >
            <x-slot name="heading">Update password</x-slot>
            <x-slot name="description">
                Choose a long, unique password. At least 12 characters is
                recommended.
            </x-slot>

            <form wire:submit="updatePassword" class="orbital-form-stack">
                <div class="orbital-form-field">
                    <label>Current password</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            wire:model="currentPassword"
                            type="password"
                            autocomplete="current-password"
                            required
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="orbital-form-field">
                    <label>New password</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            wire:model="newPassword"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="orbital-form-field">
                    <label>Confirm new password</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            wire:model="newPasswordConfirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="orbital-form-actions">
                    <x-filament::button type="submit">
                        Update password
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- ── Other browser sessions ────────────────────────────── --}}
        <x-filament::section
            icon="heroicon-o-computer-desktop"
            icon-color="primary"
        >
            <x-slot name="heading">Browser sessions</x-slot>
            <x-slot name="description">
                Sign out of all other browsers and devices currently signed
                in to your account. Useful if you suspect a session has been
                compromised.
            </x-slot>

            <form wire:submit="logoutOtherSessions" class="orbital-form-stack">
                <div class="orbital-form-field">
                    <label>Confirm with your current password</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            wire:model="logoutPassword"
                            type="password"
                            autocomplete="current-password"
                            required
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="orbital-form-actions">
                    <x-filament::button type="submit" color="danger">
                        Sign out other sessions
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>

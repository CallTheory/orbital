<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\UpdateUserPassword;
use Laravel\Fortify\RecoveryCode;

/**
 * Security — per-account 2FA, password, and session management.
 *
 * Shared Filament page mounted on every panel (admin, operator,
 * portal). Hidden from sidebar navigation; linked from the user
 * menu dropdown in each panel provider.
 *
 * This page is Filament-native: every section uses Filament
 * components (sections, buttons, text inputs, badges) so it
 * inherits the panel's light/dark mode and primary color palette.
 * It calls the Fortify action classes directly rather than
 * embedding Jetstream's Livewire forms.
 */
class Security extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Security';

    protected ?string $subheading = 'Account password, two-factor authentication, and active sessions.';

    protected static ?string $slug = 'security';

    protected string $view = 'filament.pages.security';

    // Two-factor state
    public string $confirmationCode = '';

    public bool $showingRecoveryCodes = false;

    // Password change
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    // Logout other sessions
    public string $logoutPassword = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getSubheading(): ?string
    {
        return 'Two-factor authentication, password, and active browser sessions.';
    }

    // ── Two-factor authentication ───────────────────────────────────

    public function enableTwoFactor(): void
    {
        app(EnableTwoFactorAuthentication::class)(Auth::user());
        $this->showingRecoveryCodes = false;
        $this->confirmationCode = '';

        Notification::make()
            ->title('Two-factor authentication started')
            ->body('Scan the QR code with your authenticator app, then enter the 6-digit code to confirm.')
            ->success()
            ->send();
    }

    public function confirmTwoFactor(): void
    {
        try {
            app(ConfirmTwoFactorAuthentication::class)(Auth::user(), $this->confirmationCode);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Invalid code')
                ->body('The code you entered did not match. Try again with a fresh code from your authenticator.')
                ->danger()
                ->send();

            return;
        }

        $this->confirmationCode = '';
        $this->showingRecoveryCodes = true;

        Notification::make()
            ->title('Two-factor authentication enabled')
            ->body('Save your recovery codes somewhere safe — each one works once if you lose your authenticator.')
            ->success()
            ->send();
    }

    public function regenerateRecoveryCodes(): void
    {
        $user = Auth::user();
        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(
                collect(range(1, 8))->map(fn () => RecoveryCode::generate())->all(),
            )),
        ])->save();

        $this->showingRecoveryCodes = true;

        Notification::make()
            ->title('Recovery codes regenerated')
            ->body('Old codes are no longer valid. Store the new ones safely.')
            ->success()
            ->send();
    }

    public function disableTwoFactor(): void
    {
        app(DisableTwoFactorAuthentication::class)(Auth::user());
        $this->showingRecoveryCodes = false;

        Notification::make()
            ->title('Two-factor authentication disabled')
            ->warning()
            ->send();
    }

    public function getTwoFactorState(): array
    {
        $user = Auth::user()->fresh();
        $enabled = ! is_null($user->two_factor_secret);
        $confirmed = ! is_null($user->two_factor_confirmed_at);

        $qr = null;
        $secretKey = null;
        $recoveryCodes = [];

        if ($enabled) {
            $qr = $user->twoFactorQrCodeSvg();
            $secretKey = decrypt($user->two_factor_secret);
            if ($confirmed && $user->two_factor_recovery_codes) {
                $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];
            }
        }

        return [
            'enabled' => $enabled,
            'confirmed' => $confirmed,
            'qr' => $qr,
            'secret_key' => $secretKey,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    // ── Password change ─────────────────────────────────────────────

    public function updatePassword(): void
    {
        try {
            app(UpdateUserPassword::class)->update(Auth::user(), [
                'current_password' => $this->currentPassword,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPasswordConfirmation,
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' ');
            Notification::make()
                ->title('Password not updated')
                ->body($messages)
                ->danger()
                ->send();

            return;
        }

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';

        Notification::make()
            ->title('Password updated')
            ->success()
            ->send();
    }

    // ── Logout other browser sessions ───────────────────────────────

    public function logoutOtherSessions(): void
    {
        if (! Hash::check($this->logoutPassword, Auth::user()->password)) {
            Notification::make()
                ->title('Wrong password')
                ->body('Enter your current password to confirm.')
                ->danger()
                ->send();

            return;
        }

        Auth::logoutOtherDevices($this->logoutPassword);
        $this->logoutPassword = '';

        Notification::make()
            ->title('Other sessions signed out')
            ->body('Every other browser and device signed in to this account has been logged out.')
            ->success()
            ->send();
    }
}

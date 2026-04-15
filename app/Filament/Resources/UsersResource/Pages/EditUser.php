<?php

declare(strict_types=1);

namespace App\Filament\Resources\UsersResource\Pages;

use App\Filament\Resources\UsersResource;
use App\Services\Telephony\PlatformExtensionAllocator;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class EditUser extends EditRecord
{
    protected static string $resource = UsersResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('regenerate_sip_password')
                ->label('Regenerate SIP Password')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This invalidates the current softphone password. The user will need to re-enter it (or you will need to share the new one).')
                ->action(function () {
                    $newPassword = app(PlatformExtensionAllocator::class)
                        ->regeneratePassword($this->record);

                    if ($newPassword) {
                        Notification::make()
                            ->success()
                            ->title('SIP password regenerated')
                            ->body("New password: {$newPassword}")
                            ->persistent()
                            ->send();

                        $this->refreshFormData(['softphone_password']);
                    } else {
                        Notification::make()
                            ->warning()
                            ->title('No softphone extension allocated')
                            ->body('This user does not have a softphone extension yet.')
                            ->send();
                    }
                }),

            Actions\Action::make('allocate_extension')
                ->label('Allocate Softphone')
                ->icon('heroicon-o-phone-arrow-down-left')
                ->color('success')
                ->visible(fn () => app(PlatformExtensionAllocator::class)->extensionFor($this->record) === null)
                ->action(function () {
                    $ext = app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($this->record);
                    Notification::make()
                        ->success()
                        ->title("Allocated extension {$ext->number}")
                        ->send();
                    $this->refreshFormData(['softphone_extension', 'softphone_username', 'softphone_password']);
                }),

            Actions\Action::make('sendPasswordReset')
                ->label('Send password reset')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => "Email a password-reset link to {$this->record->email}? The link expires in 60 minutes.")
                ->action(function () {
                    $status = Password::broker()->sendResetLink(['email' => $this->record->email]);

                    if ($status === Password::RESET_LINK_SENT) {
                        Notification::make()
                            ->success()
                            ->title('Password reset link sent')
                            ->body("Emailed to {$this->record->email}.")
                            ->send();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Could not send reset link')
                            ->body('Status: '.$status)
                            ->send();
                    }
                }),

            Actions\Action::make('resetTwoFactor')
                ->label('Reset 2FA')
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning')
                ->visible(fn () => ! is_null($this->record->two_factor_secret))
                ->requiresConfirmation()
                ->modalHeading('Reset two-factor authentication?')
                ->modalDescription(fn () => "Disable 2FA on {$this->record->email}'s account. They'll be able to sign in with just their password until they re-enroll from their Security page. Use this when a user has lost access to their authenticator.")
                ->modalSubmitActionLabel('Reset 2FA')
                ->action(function () {
                    app(DisableTwoFactorAuthentication::class)($this->record);

                    Notification::make()
                        ->success()
                        ->title('Two-factor authentication reset')
                        ->body("{$this->record->email} will need to re-enroll on their Security page.")
                        ->send();

                    $this->refreshFormData(['two_factor_secret']);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = UsersResource::platformRoleFor($this->record);
        return $data;
    }

    protected function afterSave(): void
    {
        $roleName = $this->form->getRawState()['role'] ?? null;
        if ($roleName) {
            UsersResource::assignPlatformRole($this->record, $roleName);
        }
    }
}

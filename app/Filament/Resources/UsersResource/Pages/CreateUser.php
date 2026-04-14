<?php

declare(strict_types=1);

namespace App\Filament\Resources\UsersResource\Pages;

use App\Filament\Resources\UsersResource;
use App\Models\User;
use App\Services\Telephony\PlatformExtensionAllocator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UsersResource::class;

    protected function handleRecordCreation(array $data): User
    {
        $roleName = $this->form->getRawState()['role'] ?? 'operator';

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'] ?? Str::random(32),
            'email_verified_at' => now(),
        ]);

        UsersResource::assignPlatformRole($user, $roleName);

        // Allocate a softphone extension for this staff member.
        app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($user);

        return $user;
    }
}

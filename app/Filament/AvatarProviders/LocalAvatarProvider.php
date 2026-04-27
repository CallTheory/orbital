<?php

declare(strict_types=1);

namespace App\Filament\AvatarProviders;

use App\Services\Avatars\LocalAvatarGenerator;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Drop-in replacement for Filament's built-in `UiAvatarsProvider`.
 * Returns an inline SVG data URL from LocalAvatarGenerator instead
 * of a ui-avatars.com URL, so the panel works offline without ever
 * reaching out to the public internet.
 *
 * Registered on every panel provider via `->defaultAvatarProvider(...)`.
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function __construct(private readonly LocalAvatarGenerator $generator) {}

    public function get(Model|Authenticatable $record): string
    {
        $name = (string) Filament::getNameForDefaultAvatar($record);

        return $this->generator->dataUrlFor($name);
    }
}

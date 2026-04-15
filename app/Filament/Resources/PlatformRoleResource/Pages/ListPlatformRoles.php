<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformRoleResource\Pages;

use App\Filament\Resources\PlatformRoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatformRoles extends ListRecords
{
    protected static string $resource = PlatformRoleResource::class;

    protected ?string $subheading = 'Platform permissions for staff.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

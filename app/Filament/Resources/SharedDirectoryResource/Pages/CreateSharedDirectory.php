<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedDirectoryResource\Pages;

use App\Filament\Resources\SharedDirectoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSharedDirectory extends CreateRecord
{
    protected static string $resource = SharedDirectoryResource::class;
}

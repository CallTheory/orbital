<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExtensionResource\Pages;

use App\Filament\Resources\ExtensionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExtension extends CreateRecord
{
    protected static string $resource = ExtensionResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\LogoutReasonResource\Pages;

use App\Filament\Resources\LogoutReasonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLogoutReason extends CreateRecord
{
    protected static string $resource = LogoutReasonResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSharedContactList extends CreateRecord
{
    protected static string $resource = SharedContactListResource::class;
}

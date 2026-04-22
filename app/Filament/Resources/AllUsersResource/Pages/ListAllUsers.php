<?php

declare(strict_types=1);

namespace App\Filament\Resources\AllUsersResource\Pages;

use App\Filament\Resources\AllUsersResource;
use Filament\Resources\Pages\ListRecords;

class ListAllUsers extends ListRecords
{
    protected static string $resource = AllUsersResource::class;
}

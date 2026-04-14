<?php

declare(strict_types=1);

namespace App\Filament\Resources\CallLogResource\Pages;

use App\Filament\Resources\CallLogResource;
use Filament\Resources\Pages\ListRecords;

class ListCallLogs extends ListRecords
{
    protected static string $resource = CallLogResource::class;
}

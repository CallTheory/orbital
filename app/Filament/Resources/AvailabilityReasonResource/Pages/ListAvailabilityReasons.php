<?php

declare(strict_types=1);

namespace App\Filament\Resources\AvailabilityReasonResource\Pages;

use App\Filament\Resources\AvailabilityReasonResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAvailabilityReasons extends ListRecords
{
    protected static string $resource = AvailabilityReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

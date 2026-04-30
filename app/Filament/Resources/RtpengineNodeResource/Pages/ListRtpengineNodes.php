<?php

declare(strict_types=1);

namespace App\Filament\Resources\RtpengineNodeResource\Pages;

use App\Filament\Resources\RtpengineNodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRtpengineNodes extends ListRecords
{
    protected static string $resource = RtpengineNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoiceResource\Pages;

use App\Filament\Resources\VoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVoices extends ListRecords
{
    protected static string $resource = VoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

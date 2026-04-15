<?php

declare(strict_types=1);

namespace App\Filament\Resources\HoldMusicResource\Pages;

use App\Filament\Resources\HoldMusicResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHoldMusic extends ListRecords
{
    protected static string $resource = HoldMusicResource::class;

    protected ?string $subheading = 'Music-on-hold sources Asterisk plays to callers while they wait. The built-in "default" class is locked to keep existing dial plans stable.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

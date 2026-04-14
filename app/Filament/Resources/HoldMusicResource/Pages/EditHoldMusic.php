<?php

declare(strict_types=1);

namespace App\Filament\Resources\HoldMusicResource\Pages;

use App\Filament\Resources\HoldMusicResource;
use App\Models\HoldMusicClass;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHoldMusic extends EditRecord
{
    protected static string $resource = HoldMusicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ! ($this->record instanceof HoldMusicClass && $this->record->isDefault())),
        ];
    }
}

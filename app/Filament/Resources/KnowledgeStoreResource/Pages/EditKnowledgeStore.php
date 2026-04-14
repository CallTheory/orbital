<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeStoreResource\Pages;

use App\Filament\Resources\KnowledgeStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeStore extends EditRecord
{
    protected static string $resource = KnowledgeStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

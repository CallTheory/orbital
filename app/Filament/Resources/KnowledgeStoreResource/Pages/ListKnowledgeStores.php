<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeStoreResource\Pages;

use App\Filament\Resources\KnowledgeStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeStores extends ListRecords
{
    protected static string $resource = KnowledgeStoreResource::class;

    protected ?string $subheading = 'Searchable document collections for AI agents and live operators.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeStoreResource\Pages;

use App\Filament\Resources\KnowledgeStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeStores extends ListRecords
{
    protected static string $resource = KnowledgeStoreResource::class;

    protected ?string $subheading = 'Per-tenant document collections the AI agents can search during calls. Upload files, paste text, or add URLs.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeStoreResource\Pages;

use App\Filament\Resources\KnowledgeStoreResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeStore extends CreateRecord
{
    protected static string $resource = KnowledgeStoreResource::class;
}

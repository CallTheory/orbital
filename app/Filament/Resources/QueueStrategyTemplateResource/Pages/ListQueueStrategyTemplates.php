<?php

declare(strict_types=1);

namespace App\Filament\Resources\QueueStrategyTemplateResource\Pages;

use App\Filament\Resources\QueueStrategyTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQueueStrategyTemplates extends ListRecords
{
    protected static string $resource = QueueStrategyTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

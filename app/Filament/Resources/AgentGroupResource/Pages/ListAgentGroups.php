<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentGroupResource\Pages;

use App\Filament\Resources\AgentGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentGroups extends ListRecords
{
    protected static string $resource = AgentGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

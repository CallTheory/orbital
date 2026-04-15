<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentGroupResource\Pages;

use App\Filament\Resources\AgentGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentGroups extends ListRecords
{
    protected static string $resource = AgentGroupResource::class;

    protected ?string $subheading = 'Groups used for ACD and Interactions distributions.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

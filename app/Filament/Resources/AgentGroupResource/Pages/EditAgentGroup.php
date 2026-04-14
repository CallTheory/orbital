<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentGroupResource\Pages;

use App\Filament\Resources\AgentGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentGroup extends EditRecord
{
    protected static string $resource = AgentGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

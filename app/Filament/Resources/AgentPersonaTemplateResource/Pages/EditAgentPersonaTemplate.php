<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentPersonaTemplateResource\Pages;

use App\Filament\Resources\AgentPersonaTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentPersonaTemplate extends EditRecord
{
    protected static string $resource = AgentPersonaTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

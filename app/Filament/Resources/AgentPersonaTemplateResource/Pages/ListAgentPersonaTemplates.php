<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentPersonaTemplateResource\Pages;

use App\Filament\Resources\AgentPersonaTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentPersonaTemplates extends ListRecords
{
    protected static string $resource = AgentPersonaTemplateResource::class;

    protected ?string $subheading = 'Reusable AI voice-agent templates. Tenants create their own agent instances from these and inherit your updates until they override a field.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

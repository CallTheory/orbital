<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentPersonaTemplateResource\Pages;

use App\Filament\Resources\AgentPersonaTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentPersonaTemplate extends CreateRecord
{
    protected static string $resource = AgentPersonaTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Templates always have team_id = null and template_id = null.
        $data['team_id'] = null;
        $data['template_id'] = null;
        return $data;
    }
}

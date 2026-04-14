<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentGroupResource\Pages;

use App\Filament\Resources\AgentGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentGroup extends CreateRecord
{
    protected static string $resource = AgentGroupResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeFlowResource\Pages;

use App\Filament\Resources\IntakeFlowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntakeFlows extends ListRecords
{
    protected static string $resource = IntakeFlowResource::class;

    protected ?string $subheading = 'Cross-tenant view of every intake flow — the scripted step-by-step the AI agent and operators walk callers through.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

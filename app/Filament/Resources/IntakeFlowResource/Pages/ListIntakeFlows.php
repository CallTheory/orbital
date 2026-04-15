<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntakeFlowResource\Pages;

use App\Filament\Resources\IntakeFlowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntakeFlows extends ListRecords
{
    protected static string $resource = IntakeFlowResource::class;

    protected ?string $subheading = 'Call scripts assembled from intake goals.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

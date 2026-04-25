<?php

declare(strict_types=1);

namespace App\Filament\Resources\FlowGraphResource\Pages;

use App\Filament\Resources\FlowGraphResource;
use Filament\Resources\Pages\ListRecords;

class ListFlowGraphs extends ListRecords
{
    protected static string $resource = FlowGraphResource::class;
}

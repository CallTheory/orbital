<?php

declare(strict_types=1);

namespace App\Filament\Resources\AvailabilityReasonResource\Pages;

use App\Filament\Resources\AvailabilityReasonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAvailabilityReason extends CreateRecord
{
    protected static string $resource = AvailabilityReasonResource::class;
}

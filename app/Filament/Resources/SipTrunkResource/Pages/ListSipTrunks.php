<?php

declare(strict_types=1);

namespace App\Filament\Resources\SipTrunkResource\Pages;

use App\Filament\Resources\SipTrunkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSipTrunks extends ListRecords
{
    protected static string $resource = SipTrunkResource::class;

    protected ?string $subheading = 'External SIP trunk connectivity for DIDs and inbound/outbound calls.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

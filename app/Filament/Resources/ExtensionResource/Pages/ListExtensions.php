<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExtensionResource\Pages;

use App\Filament\Resources\ExtensionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExtensions extends ListRecords
{
    protected static string $resource = ExtensionResource::class;

    protected ?string $subheading = 'Physical SIP phones, ATAs, and standalone clients that plug into the platform PBX. Staff softphones and tenant AI agents are managed elsewhere.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

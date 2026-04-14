<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoiceResource\Pages;

use App\Filament\Resources\VoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVoice extends CreateRecord
{
    protected static string $resource = VoiceResource::class;
}

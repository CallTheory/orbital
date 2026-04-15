<?php

declare(strict_types=1);

namespace App\Filament\Resources\LogoutReasonResource\Pages;

use App\Filament\Resources\LogoutReasonResource;
use App\Models\LogoutReason;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLogoutReasons extends ListRecords
{
    protected static string $resource = LogoutReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Standing page description plus a warning appended if the
     * vocabulary is empty or every row is inactive. The operator
     * panel's logout modal degrades gracefully when there are no
     * reasons (operators can still sign out), but an empty set
     * means supervisors lose visibility into why people clock out,
     * so this is the nudge to go add some.
     */
    public function getSubheading(): ?string
    {
        $description = 'Reasons operators pick when logging out of the application.';

        $hasActive = LogoutReason::query()->where('is_active', true)->exists();

        if ($hasActive) {
            return $description;
        }

        return $description.' ⚠ No active logout reasons exist — operators can still sign out, but sessions will be logged without a reason until you add at least one active entry.';
    }
}

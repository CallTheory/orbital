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
     * Warn admins if the table is empty or every row is inactive.
     * The operator panel's logout modal degrades gracefully when
     * there are no reasons (operators can still sign out, the
     * audit row lands with a '(no reasons configured)' sentinel),
     * but an empty vocabulary means the whole feature is off and
     * supervisors lose visibility into why people clock out. This
     * subheading is the nudge to go add some.
     */
    public function getSubheading(): ?string
    {
        $hasActive = LogoutReason::query()->where('is_active', true)->exists();

        if ($hasActive) {
            return null;
        }

        return '⚠ No active logout reasons exist. Operators can still sign out, but their sessions will be logged without a reason until you add at least one active entry.';
    }
}

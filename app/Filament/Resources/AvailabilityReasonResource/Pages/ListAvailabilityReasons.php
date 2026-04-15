<?php

declare(strict_types=1);

namespace App\Filament\Resources\AvailabilityReasonResource\Pages;

use App\Filament\Resources\AvailabilityReasonResource;
use App\Models\AvailabilityReason;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAvailabilityReasons extends ListRecords
{
    protected static string $resource = AvailabilityReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Warn admins the moment the vocabulary reaches a state where
     * no operator can receive new work. With the Available row
     * protected from deletion and its toggles locked, this should
     * normally be unreachable — but if a future migration, a
     * tinker session, or a policy change ever wiped it, every
     * operator would be silently stranded. The subheading is a
     * last-line visibility net so the admin notices before anyone
     * misses a call.
     */
    public function getSubheading(): ?string
    {
        $hasAcceptingRow = AvailabilityReason::query()
            ->where('is_active', true)
            ->where('blocks_new_work', false)
            ->exists();

        if ($hasAcceptingRow) {
            return null;
        }

        return '⚠ No active "accepting work" reasons exist. Operators can\'t receive new calls or email until at least one active reason has "Block new work" turned off.';
    }
}

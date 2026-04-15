<?php

declare(strict_types=1);

namespace App\Filament\Resources\AvailabilityReasonResource\Pages;

use App\Filament\Resources\AvailabilityReasonResource;
use App\Models\AvailabilityReason;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAvailabilityReason extends EditRecord
{
    protected static string $resource = AvailabilityReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Intercept before the delete runs. For the built-in
            // Available row we fire an explanatory toast and halt
            // the action — the model's `deleting` hook would reject
            // the DB write anyway, but without this the user just
            // saw the confirmation modal close and nothing happen,
            // which read as a broken button. Bulk delete already
            // surfaces proper feedback; this brings the Edit-page
            // path in line with it.
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, AvailabilityReason $record) {
                    if ($record->slug !== AvailabilityReason::AVAILABLE) {
                        return;
                    }

                    Notification::make()
                        ->title('Can\'t delete the Available row')
                        ->body('This is the built-in accepting-work state.')
                        ->danger()
                        ->send();

                    $action->halt();
                }),
        ];
    }
}

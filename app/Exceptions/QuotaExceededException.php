<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Filament\Notifications\Notification;

class QuotaExceededException extends Exception
{
    public function __construct(
        public readonly string $resource,
        public readonly int $limit,
        string $message = '',
    ) {
        parent::__construct($message ?: "Your plan allows {$limit} {$resource}. ".config('orbital.support_message').' to upgrade.');
    }

    public function render()
    {
        Notification::make()
            ->danger()
            ->title('Plan limit reached')
            ->body($this->getMessage())
            ->persistent()
            ->send();

        return back();
    }
}

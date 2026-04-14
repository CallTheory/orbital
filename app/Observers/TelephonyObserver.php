<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RegenerateTelephonyConfig;
use Illuminate\Database\Eloquent\Model;

class TelephonyObserver
{
    public function created(Model $model): void
    {
        $this->dispatch($model);
    }

    public function updated(Model $model): void
    {
        $this->dispatch($model);
    }

    public function deleted(Model $model): void
    {
        $this->dispatch($model);
    }

    protected function dispatch(Model $model): void
    {
        $teamId = $model->team_id ?? null;
        RegenerateTelephonyConfig::dispatch($teamId);
    }
}

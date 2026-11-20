<?php

declare(strict_types=1);

use App\Services\Settings\FrozenSettingsCleanup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Settings used to store every field on each save, freezing
 * the deployment's values at that moment (on Dallas, the Ollama URL and
 * tracing endpoint kept their docker-compose addresses after the chart
 * started setting in-cluster ones). See FrozenSettingsCleanup for which
 * rows go. Nothing a person chose on purpose is removed: a removed row
 * either equals the deployment's value already or is a compose-only
 * service address the deployment overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $removed = app(FrozenSettingsCleanup::class)->run();

        if ($removed !== []) {
            Log::info('Platform Settings now follow the deployment for: '.implode(', ', $removed));
        }
    }

    public function down(): void
    {
        // The removed values were the deployment's own or unreachable
        // compose addresses; there is nothing worth putting back.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hold music classes — sources of music-on-hold audio that Asterisk can
 * play to callers who are waiting (in queues, on hold, during outages, etc.).
 *
 * Each row maps 1:1 to an Asterisk `musiconhold.conf` class. The dialplan
 * generator reads this table and emits the appropriate config blocks.
 *
 * Three source types:
 *   - builtin: uses Asterisk's bundled MOH sounds (the "default" class).
 *               No external configuration needed.
 *   - stream:  external HTTP audio stream (e.g. Icecast). Asterisk uses
 *               the `custom` MOH mode with mpg123 / similar.
 *   - files:   directory of audio files inside the Asterisk container.
 *               File upload UI is a future enhancement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hold_music_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()
                ->comment('Asterisk MOH class name. Lowercase, no spaces. e.g. "default", "jazz".');
            $table->string('label');
            $table->text('description')->nullable();

            $table->enum('type', ['builtin', 'stream', 'files'])->default('stream');

            // Stream type config
            $table->string('stream_url')->nullable();
            $table->enum('stream_format', ['mp3', 'ogg', 'aac', 'wav'])->default('mp3');

            // Files type config (directory inside the Asterisk container)
            $table->string('files_directory')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)
                ->comment('Used as the platform-wide default when no class is specified.');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_music_classes');
    }
};

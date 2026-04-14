<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per provider-specific TTS voice.
 *
 * Used as a catalog so platform operators can register voices once and
 * reference them by friendly name when configuring AI personas. The
 * actual provider call uses the provider_voice_id at runtime.
 */
class Voice extends Model
{
    public const PROVIDER_OPTIONS = [
        'elevenlabs' => 'ElevenLabs',
        'openai' => 'OpenAI TTS',
        'cartesia' => 'Cartesia',
        'deepgram' => 'Deepgram',
        'azure' => 'Azure Speech',
        'google' => 'Google Cloud TTS',
        'aws_polly' => 'AWS Polly',
        'custom' => 'Custom / Other',
    ];

    public const GENDER_OPTIONS = [
        'female' => 'Female',
        'male' => 'Male',
        'neutral' => 'Neutral',
        'unspecified' => 'Unspecified',
    ];

    protected $fillable = [
        'name',
        'provider',
        'provider_voice_id',
        'gender',
        'language',
        'accent',
        'description',
        'sample_url',
        'tags',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

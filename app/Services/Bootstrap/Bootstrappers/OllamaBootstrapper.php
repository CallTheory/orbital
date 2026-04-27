<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Makes sure Ollama has the default embedding model pulled so local
 * knowledge-store ingest works out of the box when a client picks
 * the `ollama:*` embedding provider.
 *
 * Ollama is opt-in (docker-compose `local-ai` profile), so when the
 * container isn't running this bootstrapper reports "missing" rather
 * than "error" and the seeder skips over it. Running it again after
 * starting Ollama pulls the model.
 */
class OllamaBootstrapper implements Bootstrapper
{
    public function key(): string
    {
        return 'ollama';
    }

    public function name(): string
    {
        return 'Ollama local inference';
    }

    public function description(): string
    {
        return 'Optional. Verifies Ollama is reachable and pulls the default embedding model.';
    }

    public function icon(): string
    {
        return 'heroicon-o-cpu-chip';
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function status(): BootstrapReport
    {
        $base = $this->baseUrl();
        $reachable = $this->reachable($base);

        if (! $reachable) {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: 'Ollama is not reachable at '.$base.'. Start it with: docker compose --profile local-ai up -d ollama',
            );
        }

        $target = $this->targetModel();
        $installed = $this->modelInstalled($base, $target);

        return new BootstrapReport(
            status: $installed ? BootstrapStatus::Installed : BootstrapStatus::Partial,
            message: $installed
                ? "Ollama is up; '{$target}' is cached."
                : "Ollama is up but '{$target}' hasn't been pulled yet.",
            steps: [
                ['label' => 'Reachable at '.$base, 'ok' => true, 'detail' => null],
                ['label' => "Model '{$target}' pulled", 'ok' => $installed, 'detail' => null],
            ],
        );
    }

    public function install(): BootstrapReport
    {
        $base = $this->baseUrl();

        if (! $this->reachable($base)) {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: 'Ollama is still unreachable — start the container first.',
            );
        }

        $target = $this->targetModel();

        try {
            // `ollama pull` → POST /api/pull, streams progress. We
            // don't need the progress stream, just wait for completion.
            $response = Http::timeout(600)
                ->acceptJson()
                ->asJson()
                ->post($base.'/api/pull', ['name' => $target, 'stream' => false]);

            if (! $response->successful()) {
                return new BootstrapReport(
                    status: BootstrapStatus::Error,
                    message: 'Pull failed: HTTP '.$response->status().' '.$response->body(),
                );
            }
        } catch (Throwable $e) {
            return new BootstrapReport(
                status: BootstrapStatus::Error,
                message: $e->getMessage(),
            );
        }

        return $this->status();
    }

    protected function baseUrl(): string
    {
        return rtrim((string) (config('services.ollama.url') ?: env('OLLAMA_URL', 'http://ollama:11434')), '/');
    }

    /**
     * Grab the default embedding model out of the settings registry —
     * whatever the operator picked in Platform Settings becomes what
     * we try to pull. Falls back to nomic-embed-text if the config
     * value is an openai:* model or similar.
     */
    protected function targetModel(): string
    {
        $default = (string) (config('services.embeddings.default_provider') ?: 'ollama:nomic-embed-text');
        if (str_starts_with($default, 'ollama:')) {
            return substr($default, strlen('ollama:'));
        }

        return 'nomic-embed-text';
    }

    protected function reachable(string $base): bool
    {
        try {
            $response = Http::timeout(2)->get($base.'/api/tags');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function modelInstalled(string $base, string $name): bool
    {
        try {
            $response = Http::timeout(5)->get($base.'/api/tags');
            if (! $response->successful()) {
                return false;
            }
            $models = collect($response->json('models') ?? []);

            return $models->pluck('name')->contains(function ($m) use ($name) {
                // Ollama stores names with tags like 'nomic-embed-text:latest'
                return str_starts_with((string) $m, $name);
            });
        } catch (Throwable) {
            return false;
        }
    }
}

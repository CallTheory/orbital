<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

/**
 * Result envelope returned by every bootstrapper's status() and
 * install() methods. Carries enough detail for the SystemSetup UI
 * to render a per-service card with expandable diagnostics.
 */
final class BootstrapReport
{
    /**
     * @param  array<int, array{label: string, ok: bool, detail: ?string}>  $steps
     */
    public function __construct(
        public readonly BootstrapStatus $status,
        public readonly string $message,
        public readonly array $steps = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'message' => $this->message,
            'steps' => $this->steps,
        ];
    }
}

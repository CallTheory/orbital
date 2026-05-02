<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dynamic rtpengine node registry.
 *
 * Each row is one rtpengine media-relay node that the platform
 * operator can drain, activate, or monitor through the Failover
 * Central + SIP Proxy admin pages. Mirrors {@see AsteriskBackend}
 * shape so the per-node admin UX is identical.
 */
class RtpengineNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'hostname',
        'display_name',
        'ng_host',
        'ng_port',
        'prom_host',
        'prom_port',
        'recording_spool_path',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'ng_port' => 'integer',
            'prom_port' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * NG control endpoint, host:port. Used by RtpengineService
     * to send bencoded NG commands over UDP.
     */
    public function ngEndpoint(): string
    {
        return $this->ngHost().':'.$this->ng_port;
    }

    public function ngHost(): string
    {
        return $this->ng_host !== null && $this->ng_host !== ''
            ? $this->ng_host
            : $this->hostname;
    }

    /**
     * Prometheus scrape URL for rtpengine 11.x's `--listen-prom`.
     */
    public function promUrl(): string
    {
        $host = $this->prom_host !== null && $this->prom_host !== ''
            ? $this->prom_host
            : $this->hostname;

        return "http://{$host}:{$this->prom_port}/metrics";
    }

    /**
     * Human-readable name for the UI. Falls back to hostname when
     * no display_name was set.
     */
    public function label(): string
    {
        return $this->display_name !== null && $this->display_name !== ''
            ? $this->display_name
            : $this->hostname;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

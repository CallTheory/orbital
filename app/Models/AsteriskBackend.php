<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dynamic Asterisk backend registry.
 *
 * Each row is one Asterisk node that the platform operator can
 * drain, activate, or monitor through the SIP Proxy admin page.
 * The dispatcher list Kamailio reads is regenerated from the
 * active rows on every save/delete by AsteriskDispatcherWriter.
 */
class AsteriskBackend extends Model
{
    use HasFactory;

    protected $fillable = [
        'hostname',
        'node_name',
        'display_name',
        'sip_port',
        'ami_host',
        'ami_port',
        'is_active',
        'dispatch_state',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sip_port' => 'integer',
            'ami_port' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Kamailio dispatcher.list-compatible SIP URI, e.g.
     * `sip:asterisk-2:5060`. Used when setting drain state.
     */
    public function sipUri(): string
    {
        return "sip:{$this->hostname}:{$this->sip_port}";
    }

    /**
     * Asterisk's systemname, which ps_contacts.reg_server is stamped
     * with. Discovered pods record it separately (their hostname is the
     * node IP); hand-registered backends use the hostname.
     */
    public function nodeName(): string
    {
        return $this->node_name !== null && $this->node_name !== ''
            ? $this->node_name
            : $this->hostname;
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

    /**
     * AMI host to connect to — defaults to the SIP hostname when
     * ami_host isn't explicitly set (single-container Asterisk
     * where SIP + AMI share the hostname).
     */
    public function amiHost(): string
    {
        return $this->ami_host !== null && $this->ami_host !== ''
            ? $this->ami_host
            : $this->hostname;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

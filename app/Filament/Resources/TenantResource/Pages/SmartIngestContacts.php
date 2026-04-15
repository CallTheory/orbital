<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Team;
use BackedEnum;
use Filament\Resources\Pages\Page;

/**
 * Full-page host for the ContactSmartIngest Livewire component.
 * Sits at `/admin/tenants/{record}/smart-ingest?kind=contacts|directory`
 * and is reached from the "Smart ingest" action on both the Contacts
 * and Directory sub-pages.
 *
 * Not in the tenant sub-nav — it's an action destination, not a
 * day-to-day tab.
 */
class SmartIngestContacts extends Page
{
    protected static string $resource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.smart-ingest';

    /**
     * Parent context for the ContactSmartIngest Livewire component.
     * `parentType` is hard-coded to 'team' here — the shared-list
     * variant uses its own host page. `parentId` + `parentName` are
     * plain primitives so Livewire hydration round-trips cleanly
     * (a full Eloquent model would route back through model binding
     * on every request and choke on anything non-scalar).
     */
    public string $parentType = 'team';

    public int $parentId;

    public string $parentName = '';

    public string $kind = 'contacts';

    public function mount(int | string $record): void
    {
        $team = Team::withoutGlobalScope('team')->findOrFail($record);
        $this->parentId = (int) $team->id;
        $this->parentName = (string) $team->name;
        $kind = request()->query('kind', 'contacts');
        $this->kind = in_array($kind, ['contacts', 'directory'], true) ? $kind : 'contacts';
    }

    public function getTitle(): string
    {
        return $this->kind === 'directory'
            ? 'Smart Ingest → Directory'
            : 'Smart Ingest → Contacts';
    }

    public function getSubheading(): ?string
    {
        return "Drop a file or paste text and Claude will parse it into structured rows for {$this->parentName}.";
    }
}

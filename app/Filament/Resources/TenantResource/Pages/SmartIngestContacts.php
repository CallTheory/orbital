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

    public int $teamId;

    public string $teamName = '';

    public string $kind = 'contacts';

    /**
     * Filament resource pages receive the route param as `$record`.
     * We intentionally don't expose the Team as a public Livewire
     * property — Livewire would try to serialize + rehydrate it on
     * every request, and rehydration routes the param back through
     * Eloquent's model binding which chokes on anything that isn't a
     * scalar PK. Keep it primitive (int id + plain string name) and
     * the component survives round trips cleanly.
     */
    public function mount(int | string $record): void
    {
        $team = Team::withoutGlobalScope('team')->findOrFail($record);
        $this->teamId = (int) $team->id;
        $this->teamName = (string) $team->name;
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
        return "Drop a file or paste text and Claude will parse it into structured rows for {$this->teamName}.";
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\Team;
use BackedEnum;
use Filament\Resources\Pages\Page;

/**
 * Full-page host for the DirectorySmartIngest Livewire component
 * pointed at a tenant. Lives at
 * `/admin/clients/{record}/smart-ingest` and is reached from the
 * "Smart ingest" header action on the Directory sub-page.
 *
 * Not in the client sub-nav — it's an action destination, not a
 * day-to-day tab.
 */
class SmartIngestClientDirectory extends Page
{
    protected static string $resource = ClientResource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.smart-ingest';

    public string $parentType = 'team';

    public int $parentId;

    public string $parentName = '';

    /** Kept around for the existing view's `$kind === 'directory'` branches. */
    public string $kind = 'directory';

    public function mount(int|string $record): void
    {
        $team = Team::withoutGlobalScope('team')->findOrFail($record);
        $this->parentId = (int) $team->id;
        $this->parentName = (string) $team->name;
    }

    public function getTitle(): string
    {
        return 'Smart Ingest — Directory';
    }

    public function getSubheading(): ?string
    {
        return "Drop a file or paste text and Claude will parse it into directory entries for {$this->parentName}.";
    }
}

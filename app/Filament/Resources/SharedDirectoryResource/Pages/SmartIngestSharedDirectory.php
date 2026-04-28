<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedDirectoryResource\Pages;

use App\Filament\Resources\SharedDirectoryResource;
use App\Models\SharedDirectory;
use BackedEnum;
use Filament\Resources\Pages\Page;

/**
 * Full-page host for DirectorySmartIngest pointed at a shared
 * directory instead of a tenant. Reuses the same Livewire component
 * with `parentType='shared_directory'` so the chat-style review
 * flow behaves identically to the per-tenant path.
 *
 * Not in the resource sub-nav — it's an action destination, not a
 * day-to-day tab.
 */
class SmartIngestSharedDirectory extends Page
{
    protected static string $resource = SharedDirectoryResource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.smart-ingest';

    public string $parentType = 'shared_directory';

    public int $parentId;

    public string $parentName = '';

    /** Kept around for the existing view's `$kind === 'directory'` branches. */
    public string $kind = 'directory';

    public function mount(int|string $record): void
    {
        $directory = SharedDirectory::findOrFail($record);
        $this->parentId = (int) $directory->id;
        $this->parentName = (string) $directory->name;
    }

    public function getTitle(): string
    {
        return 'Smart Ingest — Shared Directory';
    }

    public function getSubheading(): ?string
    {
        return "Drop a file or paste text and Claude will parse it into entries for \"{$this->parentName}\".";
    }
}

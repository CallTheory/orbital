<?php

declare(strict_types=1);

namespace App\Filament\Resources\SharedContactListResource\Pages;

use App\Filament\Resources\SharedContactListResource;
use App\Models\SharedContactList;
use BackedEnum;
use Filament\Resources\Pages\Page;

/**
 * Full-page host for ContactSmartIngest pointed at a shared contact
 * list instead of a tenant. Reached from the "Smart ingest" action
 * on ManageSharedContactListContacts; reuses the shared Livewire
 * component with parentType='shared_contact_list' so the chat-style
 * review flow behaves identically to the tenant path.
 *
 * Not in the resource sub-nav — it's an action destination, not a
 * day-to-day tab.
 */
class SmartIngestSharedContactList extends Page
{
    protected static string $resource = SharedContactListResource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Reuses the existing smart-ingest blade so there's one view
     * behind both hosts. The blade mounts `contact-smart-ingest`
     * with whatever props are available on the page class.
     */
    protected string $view = 'filament.pages.smart-ingest';

    public int $parentId;

    public string $parentType = 'shared_contact_list';

    /** Shared lists only have a contacts schema — there's no shared directory model. */
    public string $kind = 'contacts';

    public string $listName = '';

    /**
     * Filament resource pages receive the route param as `$record`.
     * Keeping the state primitive (id + name) so Livewire hydration
     * round-trips cleanly without trying to re-bind an Eloquent model
     * through the URL on every request.
     */
    public function mount(int | string $record): void
    {
        $list = SharedContactList::findOrFail($record);
        $this->parentId = (int) $list->id;
        $this->listName = (string) $list->name;
    }

    public function getTitle(): string
    {
        return 'Smart Ingest → Shared List';
    }

    public function getSubheading(): ?string
    {
        return "Drop a file or paste text and Claude will parse it into entries for \"{$this->listName}\".";
    }
}

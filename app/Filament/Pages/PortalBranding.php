<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersRegistrySettings;
use App\Services\Settings\ServiceRestartCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Customer-portal branding — name, logo, favicon. Registry-driven
 * in the same pattern as ProvidersSettings; reuses the shared
 * platform-settings view for its render.
 *
 * Data flows into:
 *   - PortalPanelProvider (brandName / brandLogo / favicon)
 *   - resources/views/auth/*.blade.php (the single unified login
 *     page every user hits before panel routing)
 */
class PortalBranding extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;
    use RendersRegistrySettings;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paint-brush';

    protected static string|UnitEnum|null $navigationGroup = 'Preferences';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Portal Branding';

    protected static ?string $title = 'Portal Branding';

    protected ?string $subheading = 'Visual identity shown on the customer portal and the shared login page.';

    protected static ?string $slug = 'portal-branding';

    protected string $view = 'filament.pages.platform-settings';

    /** @var array<int, string> */
    protected array $sectionKeys = ['portal_branding'];

    // Only one section on this page — collapsing it would just hide
    // everything, so keep it always open.
    protected bool $collapsibleSections = false;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->registrySectionState($this->sectionKeys));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema($this->registrySectionComponents($this->sectionKeys));
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $slugs = $this->saveRegistrySections($this->form->getState(), $this->sectionKeys);

        if ($slugs === []) {
            Notification::make()
                ->title('Portal branding saved')
                ->success()
                ->send();

            return;
        }

        $labels = ServiceRestartCatalog::labelsFor($slugs);
        Notification::make()
            ->title('Saved — restart required')
            ->body('The following services need a restart: '.implode(', ', $labels).'.')
            ->warning()
            ->persistent()
            ->send();
    }
}

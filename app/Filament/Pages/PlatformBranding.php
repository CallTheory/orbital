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
 * Platform-operator identity — name, operating company, support
 * copy, and admin/operator panel logos + favicon. Sibling to
 * PortalBranding (which handles the customer-facing identity).
 *
 * Promoted out of the System → Settings page into its own top-
 * level System entry so operators find branding where they'd
 * expect it, not buried under a generic "Settings" catch-all.
 */
class PlatformBranding extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;
    use RendersRegistrySettings;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Branding';

    protected static ?string $title = 'Platform Branding';

    protected ?string $subheading = 'Identity shown on the admin and operator panels.';

    protected static ?string $slug = 'platform-branding';

    protected string $view = 'filament.pages.platform-settings';

    /** @var array<int, string> */
    protected array $sectionKeys = ['branding'];

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
                ->title('Platform branding saved')
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

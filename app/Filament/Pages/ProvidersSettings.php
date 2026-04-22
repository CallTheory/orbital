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
 * AI provider credentials — API keys for the LLM / STT / TTS /
 * embeddings services the platform can talk to. Split out of the
 * main Platform Settings page so the Conversational AI nav group
 * has the knobs that affect agent behavior close to where you
 * edit personas and flows.
 */
class ProvidersSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;
    use RendersRegistrySettings;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Conversational AI';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Providers';

    protected static ?string $title = 'Provider Credentials';

    protected ?string $subheading = 'API keys for the LLM, STT, TTS, and embedding providers Orbital can talk to.';

    protected static ?string $slug = 'providers';

    protected string $view = 'filament.pages.platform-settings';

    /** @var array<int, string> */
    protected array $sectionKeys = ['ai_providers'];

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
                ->title('Provider credentials saved')
                ->success()
                ->send();
            return;
        }

        $labels = ServiceRestartCatalog::labelsFor($slugs);
        Notification::make()
            ->title('Saved — restart required')
            ->body('The following services need a restart to pick up the change: '.implode(', ', $labels).'.')
            ->warning()
            ->persistent()
            ->send();
    }
}

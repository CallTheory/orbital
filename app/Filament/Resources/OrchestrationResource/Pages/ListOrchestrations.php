<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrchestrationResource\Pages;

use App\Filament\Resources\OrchestrationResource;
use App\Models\Orchestration;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;

class ListOrchestrations extends ListRecords
{
    protected static string $resource = OrchestrationResource::class;

    /**
     * Platform-shared orchestrations have no owning client. Authoring
     * one happens here on the cross-client list — once saved, the
     * super-admin opens it in the Svelte editor and the assigning
     * client supplies bindings on its queue.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_platform_orchestration')
                ->label('Create Platform Orchestration')
                ->icon('heroicon-o-globe-alt')
                ->color('info')
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(160)
                        ->placeholder('e.g. Standard Intake'),
                    Forms\Components\Textarea::make('description')
                        ->rows(2)
                        ->maxLength(1000)
                        ->placeholder('Optional summary of what this shared orchestration does. Helps clients decide when to wire it in.'),
                ]))
                ->action(function (array $data) {
                    $orchestration = Orchestration::create([
                        'team_id' => null,
                        'name' => $data['name'],
                        'description' => $data['description'] ?? null,
                    ]);
                    Notification::make()
                        ->title('Platform orchestration created')
                        ->body('Open it in the editor to lay out flows. Each assigning client supplies its own bindings.')
                        ->success()
                        ->send();

                    $this->redirect(route('admin.flow-editor', ['orchestration' => $orchestration->id]));
                }),
        ];
    }
}

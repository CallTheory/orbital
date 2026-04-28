<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\CallQueue;
use App\Models\ChatQueue;
use App\Models\EmailQueue;
use App\Models\MessageQueue;
use App\Models\Team;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The unified Channels hub for a client. One sidebar entry that
 * exposes call, email, message, and chat queues as tabs. Each
 * tab swaps the page-level table to the matching channel's
 * query/columns/form via static helpers on the per-channel
 * `ManageClient*Queues` pages — full create / edit / bindings /
 * delete flow lives here, no second sidebar.
 *
 * The four per-channel pages stay route-reachable on their own
 * routes for deep-link compatibility but drop out of the
 * sidebar in favor of this hub.
 */
class ManageClientChannels extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ClientResource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Channels';

    protected static ?string $title = 'Channels';

    protected string $view = 'filament.pages.manage-client-channels';

    /** call | email | message | chat */
    public string $activeTab = 'call';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['call', 'email', 'message', 'chat'], true)) {
            return;
        }
        $this->activeTab = $tab;
        $this->resetTable();
    }

    public function getTeam(): Team
    {
        return $this->getRecord();
    }

    public function table(Table $table): Table
    {
        $owner = $this->getTeam();

        [$query, $editAction, $createAction, $modelLabel] = match ($this->activeTab) {
            'email' => [
                EmailQueue::query()->where('team_id', $owner->id),
                ManageClientEmailQueues::editAction($owner),
                Actions\CreateAction::make()
                    ->model(EmailQueue::class)
                    ->mutateDataUsing(fn (array $data) => $data + ['team_id' => $owner->id])
                    ->schema(ManageClientEmailQueues::formSchemaFor($owner)),
                'email queue',
            ],
            'message' => [
                MessageQueue::query()->where('team_id', $owner->id),
                ManageClientMessageQueues::editAction($owner),
                Actions\CreateAction::make()
                    ->model(MessageQueue::class)
                    ->mutateDataUsing(fn (array $data) => $data + ['team_id' => $owner->id])
                    ->schema(ManageClientMessageQueues::formSchemaFor($owner)),
                'message queue',
            ],
            'chat' => [
                ChatQueue::query()->where('team_id', $owner->id),
                ManageClientChatQueues::editAction($owner),
                Actions\CreateAction::make()
                    ->model(ChatQueue::class)
                    ->mutateDataUsing(fn (array $data) => $data + ['team_id' => $owner->id])
                    ->schema(ManageClientChatQueues::formSchemaFor($owner)),
                'chat queue',
            ],
            default => [
                CallQueue::query()->where('team_id', $owner->id),
                ManageClientCallQueues::editAction($owner),
                Actions\CreateAction::make()
                    ->model(CallQueue::class)
                    ->mutateDataUsing(fn (array $data) => $data + ['team_id' => $owner->id])
                    ->schema(ManageClientCallQueues::formSchemaFor($owner)),
                'call queue',
            ],
        };

        $columns = match ($this->activeTab) {
            'email' => ManageClientEmailQueues::tableColumns($editAction),
            'message' => ManageClientMessageQueues::tableColumns($editAction),
            'chat' => ManageClientChatQueues::tableColumns($editAction),
            default => ManageClientCallQueues::tableColumns($editAction),
        };

        return $table
            ->query(fn (): Builder => $query)
            ->recordTitleAttribute('name')
            ->columns($columns)
            ->defaultSort('name')
            ->headerActions([$createAction])
            ->actions([])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No '.$modelLabel.'s yet')
            ->emptyStateDescription('Create one with the New button above.');
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string}>
     */
    public function getChannelTabs(): array
    {
        return [
            ['key' => 'call', 'label' => 'Call', 'icon' => 'heroicon-o-phone'],
            ['key' => 'email', 'label' => 'Email', 'icon' => 'heroicon-o-envelope'],
            ['key' => 'message', 'label' => 'Message', 'icon' => 'heroicon-o-chat-bubble-bottom-center-text'],
            ['key' => 'chat', 'label' => 'Chat', 'icon' => 'heroicon-o-chat-bubble-left-right'],
        ];
    }
}

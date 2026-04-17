<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\EmailMessage;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Admin-only page that lists inbound email messages the router
 * couldn't place on any tenant. Click a row to view, forward,
 * assign to a tenant, or discard.
 */
class UnroutedMail extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Monitor';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Failed Inbound Mail';

    protected static ?string $title = 'Failed Inbound Mail';

    protected ?string $subheading = 'Messages that were accepted but failed during processing.';

    protected static ?string $slug = 'mail/failed';

    protected string $view = 'filament.pages.unrouted-mail';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = EmailMessage::where('routing_status', 'failed')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * When a new failed message arrives (broadcast via Reverb),
     * refresh the sidebar badge and the table so the admin sees
     * it immediately without a page reload.
     */
    #[On('echo:admin-alerts,.inbound-mail-failed')]
    public function onInboundMailFailed(): void
    {
        $this->dispatch('refresh-sidebar');
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');

        return $table
            ->query(
                EmailMessage::query()
                    ->where('routing_status', 'failed')
                    ->latest('received_at'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('M j, g:i a T')
                    ->timezone($tz)
                    ->sortable()
                    ->color('gray')
                    ->url(fn (EmailMessage $record) => url("/admin/mail/failed/{$record->id}")),
                Tables\Columns\TextColumn::make('from_address')
                    ->label('From')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (EmailMessage $record) => $record->subject ?: null)
                    ->url(fn (EmailMessage $record) => url("/admin/mail/failed/{$record->id}")),
                Tables\Columns\TextColumn::make('envelope_to')
                    ->label('Delivered to')
                    ->getStateUsing(fn (EmailMessage $record) => implode(', ', $record->metadata['envelope_to'] ?? []))
                    ->limit(40),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Error')
                    ->getStateUsing(fn (EmailMessage $record) => $record->metadata['last_error'] ?? null)
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_discard')
                        ->label('Discard selected')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $r) {
                                Storage::disk('s3')->delete($r->raw_storage_path);
                                $r->delete();
                                $count++;
                            }
                            $this->dispatch('refresh-sidebar');
                            Notification::make()->title("Discarded {$count} messages")->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('received_at', 'desc');
    }
}

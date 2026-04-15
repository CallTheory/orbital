<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\EmailMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin-only page that lists inbound email messages the router
 * couldn't place on any tenant — either the recipient
 * account_number didn't resolve to a real team, or no
 * EmailRoutingRule matched after the tenant was resolved, or the
 * parse failed outright.
 *
 * Useful for debugging:
 *   - a tenant just onboarded and nobody's set up their rules yet
 *   - a typo in a rule pattern
 *   - Haraka accepting something our router doesn't understand
 *
 * Super-admin gated because it surfaces raw inbound mail that
 * hasn't been filtered by tenant permissions yet.
 */
class UnroutedMail extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Monitor';

    protected static ?string $navigationLabel = 'Unrouted Mail';

    protected static ?string $title = 'Unrouted Mail';

    protected ?string $subheading = 'Inbound email messages not matching a tenant rule.';

    protected static ?string $slug = 'mail/unrouted';

    protected string $view = 'filament.pages.unrouted-mail';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EmailMessage::query()
                    ->whereIn('routing_status', ['unrouted', 'failed'])
                    ->latest('received_at'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('M j, g:i a')
                    ->timezone(fn () => auth()->user()?->displayTimezone() ?? config('app.timezone'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('routing_status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'failed' => 'danger',
                        'unrouted' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('from_address')
                    ->label('From')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('envelope_to')
                    ->label('Delivered to')
                    ->getStateUsing(fn (EmailMessage $record) => implode(', ', $record->metadata['envelope_to'] ?? []))
                    ->wrap(),
                Tables\Columns\TextColumn::make('subject')
                    ->searchable()
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Error')
                    ->getStateUsing(fn (EmailMessage $record) => $record->metadata['last_error'] ?? null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(80),
            ])
            ->actions([
                Action::make('view_raw')
                    ->label('Raw MIME')
                    ->icon('heroicon-m-document-text')
                    ->color('gray')
                    ->modalHeading(fn (EmailMessage $record) => "Raw message #{$record->id}")
                    ->modalContent(fn (EmailMessage $record) => view('filament.pages.partials.raw-mail-viewer', [
                        'raw' => \Illuminate\Support\Facades\Storage::disk('s3')->get($record->raw_storage_path),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('delete')
                    ->label('Discard')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This deletes the DB row and the raw MIME blob in MinIO. Use for junk that you\'re sure you don\'t need.')
                    ->action(function (EmailMessage $record) {
                        \Illuminate\Support\Facades\Storage::disk('s3')->delete($record->raw_storage_path);
                        $record->delete();
                        Notification::make()->title('Discarded')->success()->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_discard')
                        ->label('Discard selected')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $count = 0;
                            foreach ($records as $r) {
                                \Illuminate\Support\Facades\Storage::disk('s3')->delete($r->raw_storage_path);
                                $r->delete();
                                $count++;
                            }
                            Notification::make()->title("Discarded {$count} messages")->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('received_at', 'desc');
    }
}

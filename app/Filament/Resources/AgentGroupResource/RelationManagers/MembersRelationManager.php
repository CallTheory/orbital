<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentGroupResource\RelationManagers;

use App\Models\Extension;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manages the polymorphic members of an AgentGroup. Members can be
 * staff users (humans on softphones) or platform phone extensions
 * (hardware devices).
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('member_type')
                    ->label('Member type')
                    ->options([
                        User::class => 'Staff user',
                        Extension::class => 'Phone extension',
                    ])
                    ->required()
                    ->live(),
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->options(function (callable $get) {
                        $type = $get('member_type');
                        if ($type === User::class) {
                            return self::staffUserOptions();
                        }
                        if ($type === Extension::class) {
                            return self::platformExtensionOptions();
                        }
                        return [];
                    })
                    ->searchable()
                    ->required()
                    ->visible(fn (callable $get) => filled($get('member_type'))),
                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->default(0)
                    ->helperText('Order within the group for sequential strategies.'),
                Forms\Components\TextInput::make('penalty')
                    ->numeric()
                    ->default(0)
                    ->helperText('Asterisk queue penalty — lower = preferred.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('member_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        User::class => 'Staff',
                        Extension::class => 'Extension',
                        default => class_basename($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        User::class => 'success',
                        Extension::class => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('member_label')
                    ->label('Member')
                    ->getStateUsing(function ($record): string {
                        $member = $record->member;
                        if (! $member) return '(deleted)';
                        if ($member instanceof User) return $member->name.' <'.$member->email.'>';
                        if ($member instanceof Extension) return $member->number.' '.($member->label ? "({$member->label})" : '');
                        return (string) $member->id;
                    }),
                Tables\Columns\TextColumn::make('priority')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('penalty')
                    ->alignCenter(),
            ])
            ->defaultSort('priority')
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add member')
                    ->using(function (array $data, $livewire) {
                        return $livewire->getOwnerRecord()->members()->create($data);
                    }),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Platform staff users (anyone with a team-less role).
     *
     * @return array<int, string>
     */
    protected static function staffUserOptions(): array
    {
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');

        return User::query()
            ->whereExists(function ($query) use ($modelHasRoles) {
                $query->select(\DB::raw(1))
                    ->from($modelHasRoles)
                    ->whereColumn($modelHasRoles.'.model_id', 'users.id')
                    ->where($modelHasRoles.'.model_type', User::class)
                    ->whereNull($modelHasRoles.'.team_id');
            })
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} <{$u->email}>"])
            ->all();
    }

    /**
     * Platform PBX extensions — non-tenant, non-staff-softphone hardware
     * devices that can be group members.
     *
     * @return array<int, string>
     */
    protected static function platformExtensionOptions(): array
    {
        return Extension::withoutGlobalScope('team')
            ->whereNull('team_id')
            ->whereIn('type', ['sip_phone', 'ata', 'softphone', 'webrtc_client'])
            ->orderBy('number')
            ->get()
            ->mapWithKeys(fn (Extension $e) => [$e->id => "{$e->number} ".($e->label ? "({$e->label})" : '')])
            ->all();
    }
}

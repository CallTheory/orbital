<?php

declare(strict_types=1);

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared behavior for Filament resources that are managed exclusively by
 * platform operators (super-admin) on behalf of clients.
 *
 * Three things:
 *   1. All CRUD operations are gated to super_admin
 *   2. Queries bypass the BelongsToTeam global scope so super-admin sees
 *      records across every client (plus platform-wide records with team_id = null)
 *   3. (Consumers add their own client column/filter on top of this)
 */
trait PlatformManagedResource
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('team');
    }
}

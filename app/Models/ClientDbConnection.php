<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named database connection the client's flows can use via the
 * db_* primitives. Password is encrypted at rest.
 */
class ClientDbConnection extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    public const DRIVER_POSTGRES = 'postgres';

    public const DRIVER_MYSQL = 'mysql';

    public const DRIVER_MSSQL = 'mssql';

    public const DRIVER_ORACLE = 'oracle';

    public const DRIVER_SQLITE = 'sqlite';

    public const DRIVER_ODBC = 'odbc';

    public const DRIVER_REST_API = 'rest_api';

    public const DRIVERS = [
        self::DRIVER_POSTGRES,
        self::DRIVER_MYSQL,
        self::DRIVER_MSSQL,
        self::DRIVER_ORACLE,
        self::DRIVER_SQLITE,
        self::DRIVER_ODBC,
        self::DRIVER_REST_API,
    ];

    protected $fillable = [
        'team_id',
        'name',
        'driver',
        'host',
        'port',
        'database',
        'schema',
        'username',
        'password',
        'options',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'options' => 'array',
            'port' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

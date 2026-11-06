<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for Fuel ID Numbers. Metadata never contains a PIN.
 *
 * @property int $id
 * @property string $action
 * @property int|null $actor_user_id
 * @property int|null $fuel_id_source_id
 * @property int|null $fuel_id_row_id
 * @property int|null $employee_id
 * @property array<string, mixed>|null $metadata
 */
final class FuelIdEvent extends Model
{
    public const UPDATED_AT = null;

    public const IMPORTED = 'imported';

    public const REVEALED = 'pin_revealed';

    public const RESOLVED = 'exception_resolved';

    public const DISMISSED = 'exception_dismissed';

    public const SOURCE_VIEWED = 'source_viewed';

    public const SOURCE_DOWNLOADED = 'source_downloaded';

    protected $table = 'fuel_id_events';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One SCBA or radio number on an apparatus as of its latest inspection. Each inspection keeps its own copy of what was
 * entered, so this row is only the current value and may change or be removed.
 *
 * @property int $id
 * @property int $apparatus_id
 * @property string $slot
 * @property string $kind
 * @property string|null $label
 * @property string $number
 */
final class ApparatusEquipmentNumber extends Model
{
    protected $guarded = ['id'];
}

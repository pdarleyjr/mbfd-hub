<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentExpirationNotification extends Model
{
    protected $guarded = [];

    protected $casts = ['expiration_date' => 'date', 'sent_at' => 'datetime'];
}

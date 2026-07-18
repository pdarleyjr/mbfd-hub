<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OperationalFormEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'form_record_id', 'document_id', 'employee_id', 'user_id',
        'event_type', 'request_ip_hash', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}

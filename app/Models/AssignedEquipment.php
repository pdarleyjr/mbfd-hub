<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignedEquipment extends Model
{
    protected $fillable = [
        'user_id',
        'employee_portal_id',
        'uniform_id',
        'category',
        'item_description',
        'quantity',
        'issued_at',
        'notes',
        'status',
        'expires_at',
        'returned_at',
        'source_personnel_request_item_id',
        'retired_by_id',
        'retirement_reason',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'expires_at' => 'date',
        'returned_at' => 'date',
        'quantity' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_portal_id');
    }

    public function uniform(): BelongsTo
    {
        return $this->belongsTo(Uniform::class);
    }

    public function sourcePersonnelRequestItem(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequestItem::class, 'source_personnel_request_item_id');
    }

    public static function categories(): array
    {
        return [
            'Uniform Inventory',
            'T-Shirts',
            'Polo Shirts',
            'Uniform Pants',
            'Bunker Coat',
            'Bunker Pants',
            'Helmet',
            'Gloves',
            'Boots',
            'Hood',
            'SCBA Mask',
            'Belt',
            'Badge',
            'Jacket',
            'Other',
        ];
    }
}

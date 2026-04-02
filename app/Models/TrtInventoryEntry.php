<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrtInventoryEntry extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'catalog_item_id',
        'present',
        'actual_quantity',
        'condition',
        'action',
        'image_path',
    ];

    protected $casts = [
        'present' => 'boolean',
        'actual_quantity' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrtInventorySession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(TrtInventoryCatalogItem::class, 'catalog_item_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Chatify\Traits\UUID;

/**
 * Chatify Favorite Model
 * 
 * This model represents favorites in the Chatify chat system.
 * It stores favorited contacts for each user.
 */
class ChFavorite extends Model
{
    use UUID;

    protected $table = 'ch_favorites';

    protected $fillable = [
        'user_id',
        'favorite_id',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;
}

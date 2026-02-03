<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = [
        'name',
        'station_number',
        'address',
        'phone',
        'latitude',
        'longitude',
        'is_active',
        'notes',
        'image_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    /**
     * Get all apparatuses assigned to this station
     */
    public function apparatuses(): HasMany
    {
        return $this->hasMany(Apparatus::class);
    }

    /**
     * Get apparatuses by current_location text field (not FK relationship)
     * This filters apparatuses where current_location = 'Station {station_number}'
     */
    public function apparatusesByLocation()
    {
        return Apparatus::where('current_location', 'Station ' . $this->station_number);
    }

    /**
     * Get active apparatuses at this station
     */
    public function activeApparatuses(): HasMany
    {
        return $this->hasMany(Apparatus::class)->where('status', 'Active');
    }

    /**
     * Get capital projects for this station
     */
    public function capitalProjects(): HasMany
    {
        return $this->hasMany(CapitalProject::class);
    }

    /**
     * Get active capital projects for this station
     */
    public function activeCapitalProjects(): HasMany
    {
        return $this->hasMany(CapitalProject::class)->active();
    }

    /**
     * Get under 25k projects for this station
     */
    public function under25kProjects(): HasMany
    {
        return $this->hasMany(Under25kProject::class);
    }

    /**
     * Get active under 25k projects for this station
     */
    public function activeUnder25kProjects(): HasMany
    {
        return $this->hasMany(Under25kProject::class)->active();
    }

    /**
     * Get rooms for this station
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Get personnel assigned to this station
     */
    public function personnel(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }

    /**
     * Calculate the number of dorm beds based on apparatus assignments
     * This is a dynamic calculation - not a stored attribute
     */
    public function getDormBedsCountAttribute(): int
    {
        // Count unique personnel with 'Dorm' assignment at this station
        return $this->personnel()
            ->where('assignment', 'Dorm')
            ->where('status', 'Active')
            ->count();
    }

    /**
     * Get all shop works for this station
     */
    public function shopWorks(): HasMany
    {
        return $this->hasMany(ShopWork::class);
    }

    /**
     * Get active shop works for this station
     */
    public function activeShopWorks(): HasMany
    {
        return $this->hasMany(ShopWork::class)->whereIn('status', ['Pending', 'In Progress']);
    }

    /**
     * Get big ticket requests for this station.
     */
    public function bigTicketRequests()
    {
        return $this->hasMany(BigTicketRequest::class);
    }

    /**
     * Get inventory submissions for this station.
     */
    public function inventorySubmissions()
    {
        return $this->hasMany(StationInventorySubmission::class);
    }
}

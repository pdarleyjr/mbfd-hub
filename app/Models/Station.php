<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Storage;
use LogicException;

class Station extends Model
{
    protected $fillable = [
        'name',
        'station_number',
        'address',
        'city',
        'state',
        'zip_code',
        'captain_in_charge',
        'phone',
        'latitude',
        'longitude',
        'is_active',
        'notes',
        'image_path',
    ];

    /** Station photos are non-sensitive building images served from the public disk. */
    public const IMAGE_DISK = 'public';

    public const IMAGE_DIRECTORY = 'stations/photos';

    /** Shown when a station has neither an uploaded photo nor bundled artwork. */
    public const PLACEHOLDER_IMAGE = '/images/mbfd-official-seal-256.png';

    /** @var list<string> */
    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Upload limit in kilobytes. */
    public const IMAGE_MAX_KILOBYTES = 5120;

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Station $station): void {
            if (StationInspection::query()->where('station_id', $station->getKey())->exists()) {
                throw new LogicException('Stations with submitted inspection evidence cannot be deleted. Deactivate the station instead.');
            }
        });

        // A replaced or removed photo is no longer referenced anywhere.
        static::updated(function (Station $station): void {
            if (! $station->wasChanged('image_path')) {
                return;
            }

            $previous = $station->getOriginal('image_path');
            if (is_string($previous) && $previous !== '' && $previous !== $station->getAttribute('image_path')) {
                Storage::disk(self::IMAGE_DISK)->delete($previous);
            }
        });
    }

    /**
     * Uploaded photo, else the bundled station artwork the member SPA also
     * falls back to, else the department seal. Always a same-origin path.
     */
    public function photoOrPlaceholderUrl(): string
    {
        $uploaded = $this->image_url;
        if ($uploaded !== null) {
            return $uploaded;
        }

        $number = (string) preg_replace('/\D/', '', (string) $this->getAttribute('station_number'));
        if ($number === '') {
            return self::PLACEHOLDER_IMAGE;
        }

        // Optimized WebP first (browser-rendered only); the PNG original is the fallback.
        foreach (['webp', 'png'] as $extension) {
            $bundled = '/images/stations/station-'.$number.'.'.$extension;
            if (is_file(public_path(ltrim($bundled, '/')))) {
                return $bundled;
            }
        }

        return self::PLACEHOLDER_IMAGE;
    }

    /**
     * Same-origin URL path of the uploaded station photo, or null when none is
     * recorded. Root-relative so the member SPA can use it on any host.
     */
    public function getImageUrlAttribute(): ?string
    {
        $path = $this->getAttribute('image_path');
        if (! is_string($path) || trim($path) === '' || str_contains($path, '..')) {
            return null;
        }

        $url = Storage::disk(self::IMAGE_DISK)->url(ltrim($path, '/'));
        $relative = parse_url($url, PHP_URL_PATH);

        return is_string($relative) && str_starts_with($relative, '/') ? $relative : null;
    }

    /**
     * Display name: the administrator-entered name, or "Station {number}" when
     * no name has been set. Code that needs only the stored value should use
     * getRawOriginal('name').
     */
    public function getNameAttribute(?string $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : 'Station '.$this->station_number;
    }

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
        return Apparatus::where('current_location', 'Station '.$this->station_number);
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
     * Get all shop works for this station (through apparatuses).
     * shop_works has apparatus_id, not station_id.
     */
    public function shopWorks(): HasManyThrough
    {
        return $this->hasManyThrough(ShopWork::class, Apparatus::class);
    }

    /**
     * Get active shop works for this station (through apparatuses).
     */
    public function activeShopWorks(): HasManyThrough
    {
        return $this->hasManyThrough(ShopWork::class, Apparatus::class)
            ->whereIn('shop_works.status', ['Pending', 'In Progress']);
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

    /**
     * Get single gas meters for apparatuses at this station
     * This uses a hasManyThrough relationship via apparatuses
     */
    public function singleGasMeters()
    {
        return $this->hasManyThrough(
            SingleGasMeter::class,
            Apparatus::class,
            'station_id', // Foreign key on apparatuses table
            'apparatus_id', // Foreign key on single_gas_meters table
            'id', // Local key on stations table
            'id' // Local key on apparatuses table
        );
    }

    /**
     * Get v2 inventory items for this station
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(StationInventoryItem::class);
    }

    /**
     * Get v2 inventory audits for this station
     */
    public function inventoryAudits(): HasMany
    {
        return $this->hasMany(StationInventoryAudit::class);
    }

    /**
     * Get v2 supply requests for this station
     */
    public function supplyRequests(): HasMany
    {
        return $this->hasMany(StationSupplyRequest::class);
    }

    /**
     * Get fire equipment requests for this station
     */
    public function fireEquipmentRequests(): HasMany
    {
        return $this->hasMany(FireEquipmentRequest::class);
    }

    /**
     * Get canonical repair/service and station equipment requests.
     *
     * @return HasMany<StationRequest, $this>
     */
    public function stationRequests(): HasMany
    {
        return $this->hasMany(StationRequest::class);
    }

    /** @return HasMany<ApparatusServiceTicket, $this> */
    public function apparatusServiceTickets(): HasMany
    {
        return $this->hasMany(ApparatusServiceTicket::class);
    }

    /**
     * Daily Checkout / vehicle inspections for apparatus assigned to this station.
     *
     * @return HasManyThrough<ApparatusInspection, Apparatus, $this>
     */
    public function apparatusInspections(): HasManyThrough
    {
        return $this->hasManyThrough(ApparatusInspection::class, Apparatus::class);
    }

    /**
     * Missing / damaged equipment reported against this station's apparatus.
     *
     * @return HasManyThrough<ApparatusDefect, Apparatus, $this>
     */
    public function apparatusDefects(): HasManyThrough
    {
        return $this->hasManyThrough(ApparatusDefect::class, Apparatus::class);
    }

    /**
     * Daily Checkout processing exceptions for this station's apparatus.
     *
     * @return HasManyThrough<ApparatusInspectionException, Apparatus, $this>
     */
    public function apparatusInspectionExceptions(): HasManyThrough
    {
        return $this->hasManyThrough(ApparatusInspectionException::class, Apparatus::class);
    }

    /**
     * Get station inspections for this station
     */
    public function stationInspections(): HasMany
    {
        return $this->hasMany(StationInspection::class);
    }
}

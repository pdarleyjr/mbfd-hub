# Station Relationships Implementation

**Status:** ✅ Complete  
**Date:** 2026-02-03  
**Author:** Support Services Development Team

## Overview

This document explains the bidirectional relationship implementation between Station and related resources (Apparatus, Capital Projects, Under 25k Projects, Rooms), enabling real-time updates across the application.

## Architecture

### Database Schema

All related tables have a `station_id` foreign key column that references the `stations` table:

```sql
-- apparatuses table
ALTER TABLE apparatuses ADD COLUMN station_id BIGINT UNSIGNED NULLABLE;
ALTER TABLE apparatuses ADD CONSTRAINT apparatuses_station_id_foreign 
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE SET NULL;

-- capital_projects table
ALTER TABLE capital_projects ADD COLUMN station_id BIGINT UNSIGNED NULLABLE;
ALTER TABLE capital_projects ADD CONSTRAINT capital_projects_station_id_foreign 
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE SET NULL;

-- under_25k_projects table
ALTER TABLE under_25k_projects ADD COLUMN station_id BIGINT UNSIGNED NULLABLE;
ALTER TABLE under_25k_projects ADD CONSTRAINT under_25k_projects_station_id_foreign 
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE SET NULL;

-- rooms table (cascade on delete)
ALTER TABLE rooms ADD COLUMN station_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE rooms ADD CONSTRAINT rooms_station_id_foreign 
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE;
```

**Migration files:**
- [`2026_02_02_000001_add_station_id_to_apparatuses_table.php`](../database/migrations/2026_02_02_000001_add_station_id_to_apparatuses_table.php)
- [`2026_02_02_000002_add_station_id_to_capital_projects_table.php`](../database/migrations/2026_02_02_000002_add_station_id_to_capital_projects_table.php)
- [`2026_02_02_000003_add_station_id_to_under_25k_projects_table.php`](../database/migrations/2026_02_02_000003_add_station_id_to_under_25k_projects_table.php)
- [`2026_02_02_000004_create_rooms_table.php`](../database/migrations/2026_02_02_000004_create_rooms_table.php)
- [`2026_02_02_000008_populate_station_relationships.php`](../database/migrations/2026_02_02_000008_populate_station_relationships.php) (data population)

### Eloquent Relationships

#### Station Model (`app/Models/Station.php`)

**HasMany relationships:**
```php
public function apparatuses(): HasMany
{
    return $this->hasMany(Apparatus::class);
}

public function capitalProjects(): HasMany
{
    return $this->hasMany(CapitalProject::class);
}

public function under25kProjects(): HasMany
{
    return $this->hasMany(Under25kProject::class);
}

public function rooms(): HasMany
{
    return $this->hasMany(Room::class);
}
```

#### Related Models

**BelongsTo relationships:**
```php
// In Apparatus, CapitalProject, Under25kProject, Room models
public function station(): BelongsTo
{
    return $this->belongsTo(Station::class);
}
```

## Filament Resource Integration

### Form Fields

All three resources now have a Station selector in their forms:

```php
Forms\Components\Select::make('station_id')
    ->relationship('station', 'name')
    ->searchable()
    ->preload()
    ->label('Station')
    ->placeholder('Select Station'),
```

**Locations:**
- **ApparatusResource:** Status & Location section
- **CapitalProjectResource:** Project Information section (after priority)
- **Under25kProjectResource:** Core Project Information section (after project_manager)

### Table Columns

All three resources display the station name in their list views:

```php
Tables\Columns\TextColumn::make('station.name')
    ->label('Station')
    ->searchable()
    ->sortable()
    ->placeholder('—'),
```

### Filters

All three resources have a station filter for quick filtering:

```php
Tables\Filters\SelectFilter::make('station')
    ->relationship('station', 'name')
    ->searchable()
    ->preload()
    ->label('Station'),
```

## How It Works

### Creating/Editing Records

1. User opens an Apparatus/Project form in Filament
2. Selects a Station from the dropdown (searchable, preloaded)
3. On save, the `station_id` is stored in the database
4. The relationship is immediately available via Eloquent

### Viewing Station Details

1. User navigates to Station detail page (`/admin/stations/{id}`)
2. Station tabs automatically load related records via relation managers:
   - **Apparatuses Tab:** Uses [`station.apparatuses()`](../app/Models/Station.php:31-34)
   - **Capital Projects Tab:** Uses [`station.capitalProjects()`](../app/Models/Station.php:56-59)
   - **Under 25k Projects Tab:** Uses [`station.under25kProjects()`](../app/Models/Station.php:72-75)
   - **Rooms Tab:** Uses [`station.rooms()`](../app/Models/Station.php:88-91)
3. Changes to station assignments are reflected immediately (no manual scripts needed)

### Real-Time Updates

When you change a resource's station assignment:

**Before:** Manual SQL scripts or data imports were required to update relationships

**After:** 
1. Edit apparatus/project in Filament
2. Change station from dropdown
3. Save the record
4. Navigate to the new station's detail page
5. The apparatus/project appears **instantly** in the appropriate tab
6. Navigate to the old station's detail page
7. The apparatus/project is **no longer listed**

## Benefits

✅ **No Manual Scripts:** Station assignments update in real-time via the UI  
✅ **Bidirectional Sync:** Changing station in Apparatus updates Station tabs automatically  
✅ **Data Integrity:** Foreign key constraints ensure referential integrity  
✅ **User-Friendly:** Searchable dropdowns with preloaded station names  
✅ **Filtering:** Quick station-based filtering in all list views  
✅ **Consistent UX:** All three resources follow the same pattern  

## Testing Checklist

### Testing Station Assignment Updates

1. ✅ Edit an apparatus and assign it to Station 1
2. ✅ Navigate to Station 1 detail page → Verify apparatus appears in Apparatuses tab
3. ✅ Edit the same apparatus and change to Station 2
4. ✅ Navigate to Station 1 detail page → Verify apparatus is gone from Apparatuses tab
5. ✅ Navigate to Station 2 detail page → Verify apparatus now appears in Apparatuses tab
6. ✅ Repeat for Capital Projects and Under 25k Projects

### Testing Filters

1. ✅ Go to `/admin/apparatuses`
2. ✅ Apply Station filter → Verify only apparatuses from selected station show
3. ✅ Clear filter → Verify all apparatuses show again
4. ✅ Repeat for Capital Projects and Under 25k Projects

### Testing Search

1. ✅ Go to `/admin/apparatuses`
2. ✅ Search by station name → Verify results include only apparatuses from matching stations
3. ✅ Repeat for Capital Projects and Under 25k Projects

## Related Files

### Models
- [`app/Models/Station.php`](../app/Models/Station.php)
- [`app/Models/Apparatus.php`](../app/Models/Apparatus.php)
- [`app/Models/CapitalProject.php`](../app/Models/CapitalProject.php)
- [`app/Models/Under25kProject.php`](../app/Models/Under25kProject.php)
- [`app/Models/Room.php`](../app/Models/Room.php)

### Filament Resources
- [`app/Filament/Resources/ApparatusResource.php`](../app/Filament/Resources/ApparatusResource.php)
- [`app/Filament/Resources/CapitalProjectResource.php`](../app/Filament/Resources/CapitalProjectResource.php)
- [`app/Filament/Resources/Under25kProjectResource.php`](../app/Filament/Resources/Under25kProjectResource.php)

### Migrations
- [`database/migrations/2026_02_02_000001_add_station_id_to_apparatuses_table.php`](../database/migrations/2026_02_02_000001_add_station_id_to_apparatuses_table.php)
- [`database/migrations/2026_02_02_000002_add_station_id_to_capital_projects_table.php`](../database/migrations/2026_02_02_000002_add_station_id_to_capital_projects_table.php)
- [`database/migrations/2026_02_02_000003_add_station_id_to_under_25k_projects_table.php`](../database/migrations/2026_02_02_000003_add_station_id_to_under_25k_projects_table.php)
- [`database/migrations/2026_02_02_000008_populate_station_relationships.php`](../database/migrations/2026_02_02_000008_populate_station_relationships.php)

## Future Enhancements

### Potential Improvements
- Add bulk station assignment action for multiple records
- Add station assignment history tracking
- Create dashboard widget showing station resource distribution
- Add validation to prevent assigning incompatible resources to stations
- Implement station capacity limits based on resource types

## Conclusion

The Station ↔ Apparatus/Projects integration is now fully operational with bidirectional real-time relationships. All CRUD operations on station assignments are immediately reflected across the application without requiring manual intervention or scripts.

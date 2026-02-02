<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Get station IDs for mapping
        $stations = DB::table('stations')
            ->select('id', 'station_number')
            ->get()
            ->keyBy('station_number');

        // Update Capital Projects based on name patterns (column is 'name' not 'project_name')
        foreach ($stations as $stationNum => $station) {
            // Match patterns like "FIRE STATION #1", "FIRE STATION #4", etc.
            DB::table('capital_projects')
                ->whereNull('station_id')
                ->where(function ($query) use ($stationNum) {
                    $query->where('name', 'LIKE', "%FIRE STATION #$stationNum%")
                          ->orWhere('name', 'LIKE', "%FIRE STATION #$stationNum -%")
                          ->orWhere('name', 'LIKE', "%STATION $stationNum%");
                })
                ->update(['station_id' => $station->id]);
        }

        // Update Under25k Projects based on name patterns (column is 'name' not 'project_name')
        foreach ($stations as $stationNum => $station) {
            // Match patterns like "Fire Station # 1", "Fire Station # 3", etc.
            DB::table('under_25k_projects')
                ->whereNull('station_id')
                ->where(function ($query) use ($stationNum) {
                    $query->where('name', 'LIKE', "%Fire Station # $stationNum%")
                          ->orWhere('name', 'LIKE', "%Fire Station #$stationNum%")
                          ->orWhere('name', 'LIKE', "%station $stationNum%");
                })
                ->update(['station_id' => $station->id]);
        }

        // Update Apparatus based on current_location field
        foreach ($stations as $stationNum => $station) {
            DB::table('apparatuses')
                ->whereNull('station_id')
                ->where('current_location', '=', "Station $stationNum")
                ->update(['station_id' => $station->id]);
        }
    }

    public function down()
    {
        // Reset the station_id values
        DB::table('capital_projects')->update(['station_id' => null]);
        DB::table('under_25k_projects')->update(['station_id' => null]);
        DB::table('apparatuses')->update(['station_id' => null]);
    }
};

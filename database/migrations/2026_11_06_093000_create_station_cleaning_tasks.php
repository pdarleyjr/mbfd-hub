<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Station cleaning schedule (department document pages 85-86, "Station Clean Up Schedule").
 *
 * station_cleaning_tasks is configuration read at request time; nothing is written per day.
 *   - station_number NULL applies to every station; a value applies only to the station with that number, so a
 *     station row created after this migration (Station 2's Decon room) still picks up its tasks.
 *   - kind "baseline": the every-day duties (no weekday, no date). A station's own baseline replaces the department's.
 *   - kind "focus": the day's rotating assignment, for a weekday (1 = Monday .. 7 = Sunday) or one date.
 *   - kind "extra": an additional task, for a weekday or one date, shown with the focus.
 * Date rule: a date-specific focus replaces that day's weekday focus; a date-specific extra adds to that day.
 * Station-specific rows win over department rows of the same kind and day.
 *
 * station_cleaning_task_revisions is the append-only audit trail of admin changes (before and after).
 *
 * Only the permission is created here. Nobody is granted it by this migration (deploy integrity gate).
 */
return new class extends Migration
{
    private const SOURCE = 'department_schedule';

    public function up(): void
    {
        Schema::create('station_cleaning_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('station_number', 20)->nullable()->index();
            $table->string('kind', 20);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('on_date')->nullable()->index();
            $table->string('title', 160);
            $table->text('instructions')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('source', 40)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'weekday']);
        });

        Schema::create('station_cleaning_task_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_cleaning_task_id')->constrained('station_cleaning_tasks')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name', 160)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['station_cleaning_task_id', 'created_at'], 'station_cleaning_revisions_task_index');
        });

        $now = now();
        $rows = array_map(static fn (array $row): array => $row + [
            'station_number' => null,
            'weekday' => null,
            'on_date' => null,
            'is_enabled' => true,
            'sort_order' => 0,
            'source' => self::SOURCE,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->seedRows());
        DB::table('station_cleaning_tasks')->insert($rows);

        Permission::findOrCreate('stations.cleaning.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Once an admin has changed the schedule, the edits and their history are kept through a code rollback.
        $edited = DB::table('station_cleaning_task_revisions')->exists()
            || DB::table('station_cleaning_tasks')->whereNotNull('updated_by_user_id')->exists()
            || DB::table('station_cleaning_tasks')->where(fn ($query) => $query->whereNull('source')->orWhere('source', '!=', self::SOURCE))->exists();
        if ($edited) {
            throw new LogicException('The station cleaning schedule has admin edits and must be retained during rollback.');
        }
        Schema::dropIfExists('station_cleaning_task_revisions');
        Schema::dropIfExists('station_cleaning_tasks');
    }

    /** @return list<array<string, mixed>> */
    private function seedRows(): array
    {
        return [
            [
                'kind' => 'baseline',
                'title' => 'Every day',
                'instructions' => implode("\n", [
                    'Every day should still include general clean up and normal station duties.',
                    'Sweep, mop or vacuum: kitchen, dining room, bedrooms, bathrooms, day rooms, hallways, stairs, watch office, computer rooms and bay floor.',
                    'Clean all counter tops: kitchen, stove, dining room table, bathroom sinks, desks and end tables.',
                ]),
            ],
            [
                'kind' => 'focus',
                'weekday' => 1,
                'title' => 'GI Kitchen and Dining areas',
                'instructions' => 'Wash and scrub floors, walls, windows, countertops, cabinets, stove, oven, stove vent, grease trap, a/c vents, shift refrigerator, day refrigerator, microwave, toaster, dish-strainer, dishwasher, dishwasher filter, TV, ice machine, and all other appliances or furniture located in kitchen/dining area.',
            ],
            [
                'kind' => 'focus',
                'weekday' => 2,
                'title' => 'GI Bathrooms and gym (includes cardio rooms)',
                'instructions' => implode("\n", [
                    'Wash, scrub and disinfect floors, walls, counter tops, sinks, toilets, urinals, curtains, partitions, mirrors, a/c vents and any other fixtures or furniture in bathroom or gym.',
                    'To include replacing shower curtains if necessary.',
                    'Wipe down all cardio and gym equipment.',
                ]),
            ],
            [
                'kind' => 'focus',
                'weekday' => 3,
                'title' => 'Bedrooms',
                'instructions' => implode("\n", [
                    'Wash and scrub floors, walls, windows, air conditioning vents and top of lockers.',
                    'Wash all mattress covers.',
                ]),
            ],
            [
                'kind' => 'focus',
                'weekday' => 4,
                'title' => 'Common areas',
                'instructions' => implode("\n", [
                    'Wash and scrub floors, walls, windows including watch office, hallways, day rooms, stairwells.',
                    'Organize and clean supply rooms.',
                ]),
            ],
            [
                'kind' => 'focus',
                'weekday' => 5,
                'title' => 'Bay, bunker gear rooms and outside areas',
                'instructions' => implode("\n", [
                    'Wash and scrub floors, bay floor drains, walls, windows, and grill, including leaves, landscaping and exterior windows.',
                    'Organize hose racks.',
                ]),
            ],
            [
                'kind' => 'extra',
                'station_number' => '2',
                'weekday' => 5,
                'title' => 'Decon room (Station 2)',
                'instructions' => 'Wash and scrub.',
            ],
            [
                'kind' => 'focus',
                'weekday' => 6,
                'title' => 'Vehicles',
                'instructions' => 'Vacuum and clean all vehicle cabs, compartments and equipment.',
            ],
            // Sunday has no rotating assignment in the department schedule; none is invented here.
        ];
    }
};

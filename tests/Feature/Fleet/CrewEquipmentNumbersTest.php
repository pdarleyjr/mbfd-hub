<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusEquipmentNumber;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Fleet\CrewEquipmentNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** SCBA and radio numbers carry from one checkout to the next on every apparatus (docs/apparatus-issue-history.md section 7). */
class CrewEquipmentNumbersTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @var list<array{slot: string, number: string}>|null */
    private ?array $shown = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsCanonicalFixture();
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function templates(): array
    {
        return [
            'engine' => ['engine', 5, 5],
            'engine 2' => ['engine2', 5, 4],
            'ladder 1' => ['ladder1', 5, 5],
            'ladder 3' => ['ladder3', 4, 4],
            'rescue' => ['rescue', 1, 3],
            'fire boat' => ['fireboat6', 5, 8],
        ];
    }

    #[DataProvider('templates')]
    public function test_every_vehicle_type_has_its_numbers_found_and_nothing_else(string $template, int $scba, int $radios): void
    {
        $slots = app(CrewEquipmentNumbers::class)->slots($this->checklist($this->unit($template)));
        $kinds = array_count_values(array_column($slots, 'kind'));

        $this->assertSame($scba, $kinds['scba'] ?? 0);
        $this->assertSame($radios, $kinds['radio'] ?? 0);
        foreach ($slots as $slot) {
            $this->assertDoesNotMatchRegularExpression('/\b(test|bottles?|mask|facemask|hoses?|suction)\b/i', $slot['label']);
        }
    }

    public function test_the_boat_shows_at_least_four_scba_and_four_radios_and_a_rescue_three_of_each(): void
    {
        $service = app(CrewEquipmentNumbers::class);

        $boat = $service->slots($this->checklist($this->unit('fireboat6')));
        $this->assertCount(4, array_filter($boat, static fn (array $slot): bool => str_starts_with($slot['label'], 'Airpack')));
        $this->assertCount(4, array_filter($boat, static fn (array $slot): bool => str_ends_with($slot['label'], 'radio number')));

        $rescue = $service->slots($this->checklist($this->unit('rescue')));
        $this->assertSame(3, collect($rescue)->firstWhere('kind', 'scba')['boxes']);
        $this->assertCount(3, array_filter($rescue, static fn (array $slot): bool => $slot['kind'] === 'radio'));
    }

    public function test_a_number_entered_on_one_checkout_is_there_for_the_next_member_on_every_type(): void
    {
        foreach (['engine' => 'field:scba_1', 'ladder1' => 'item:scba_radio/scba_radio-item-1', 'rescue' => 'item:radio_numbers/radio_numbers-item-1', 'fireboat6' => 'field:fb6-scba-id-1'] as $template => $slot) {
            $unit = $this->unit($template);
            $this->submit($unit, [$slot => '2103']);

            $block = $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->assertOk()->json('equipment_numbers');
            $this->assertSame('2103', collect($block['slots'])->firstWhere('slot', $slot)['number'], $template);
        }
    }

    public function test_a_number_stays_until_someone_changes_it_and_a_change_replaces_it(): void
    {
        $unit = $this->unit('engine');
        $this->submit($unit, ['field:scba_1' => '2103', 'field:radio_de' => '1741'], []);
        $first = ApparatusEquipmentNumber::query()->where('slot', 'field:scba_1')->sole();

        $this->submit($unit, ['field:scba_1' => '2103', 'field:radio_de' => '1999'], []);

        $this->assertSame((int) $first->source_inspection_id, (int) ApparatusEquipmentNumber::query()->where('slot', 'field:scba_1')->sole()->source_inspection_id, 'an unchanged number keeps when it last changed');
        $this->assertSame('1999', ApparatusEquipmentNumber::query()->where('slot', 'field:radio_de')->sole()->number);
    }

    public function test_a_member_shown_the_numbers_can_clear_one_and_an_older_phone_cannot_by_accident(): void
    {
        $unit = $this->unit('engine');
        $this->submit($unit, ['field:scba_1' => '2103', 'field:scba_2' => '2161'], []);

        // An older app never showed the number, so its blank says nothing.
        $this->submit($unit, ['field:scba_2' => '2161'], null);
        $this->assertSame('2103', ApparatusEquipmentNumber::query()->where('slot', 'field:scba_1')->sole()->number);

        $this->submit($unit, ['field:scba_2' => '2161'], []);
        $this->assertDatabaseMissing('apparatus_equipment_numbers', ['slot' => 'field:scba_1']);
        $this->assertDatabaseHas('apparatus_equipment_numbers', ['slot' => 'field:scba_2', 'number' => '2161']);
    }

    public function test_more_can_be_added_on_any_type_and_they_carry_forward_until_removed(): void
    {
        $unit = $this->unit('rescue');
        $scba = (string) Str::uuid();
        $radio = (string) Str::uuid();
        $this->submit($unit, [], [
            ['client_id' => $scba, 'kind' => 'scba', 'label' => null, 'number' => '2200'],
            ['client_id' => $radio, 'kind' => 'radio', 'label' => null, 'number' => '1800'],
        ]);

        $block = $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('equipment_numbers');
        $this->assertEqualsCanonicalizing(['2200', '1800'], array_column($block['extras'], 'number'));
        $this->assertSame(['scba', 'radio'], array_column($block['extras'], 'kind'));

        $this->submit($unit, [], [['client_id' => $radio, 'kind' => 'radio', 'label' => null, 'number' => '1801']]);

        $block = $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('equipment_numbers');
        $this->assertSame(['1801'], array_column($block['extras'], 'number'), 'a removed number is gone and a changed one replaced');
    }

    public function test_added_numbers_are_on_the_inspection_record_permanently(): void
    {
        $unit = $this->unit('rescue');
        $this->submit($unit, [], [['client_id' => (string) Str::uuid(), 'kind' => 'radio', 'label' => null, 'number' => '1800']]);

        $line = collect(ApparatusInspection::query()->sole()->checklist_evidence)->firstWhere('value', '1800');
        $this->assertSame('Additional radio number', $line['name']);
    }

    public function test_the_first_checkout_after_release_starts_from_the_units_latest_inspection_and_ignores_words(): void
    {
        $unit = $this->unit('rescue');
        $this->submit($unit, ['item:compartment_d/compartment_d-item-4' => '2103, 2161, 2189', 'item:radio_numbers/radio_numbers-item-1' => 'Inspected'], null);
        ApparatusEquipmentNumber::query()->delete();

        $slots = collect($this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('equipment_numbers.slots'))->keyBy('slot');

        $this->assertSame('2103, 2161, 2189', $slots['item:compartment_d/compartment_d-item-4']['number']);
        $this->assertSame('', $slots['item:radio_numbers/radio_numbers-item-1']['number'], 'a word is not a number');
    }

    public function test_one_older_phone_filling_one_box_does_not_lose_the_units_other_numbers(): void
    {
        $unit = $this->unit('engine');
        $this->submit($unit, ['field:scba_1' => '2103', 'field:scba_2' => '2161'], null);
        ApparatusEquipmentNumber::query()->delete();

        $this->submit($unit, ['field:scba_1' => '2200'], null);

        $slots = collect($this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('equipment_numbers.slots'))->keyBy('slot');
        $this->assertSame('2200', $slots['field:scba_1']['number']);
        $this->assertSame('2161', $slots['field:scba_2']['number']);
    }

    public function test_a_word_never_replaces_a_number(): void
    {
        $unit = $this->unit('engine');
        $this->submit($unit, ['field:scba_1' => '2103'], []);

        $this->submit($unit, ['field:scba_1' => 'OK'], null);
        $this->assertDatabaseHas('apparatus_equipment_numbers', ['slot' => 'field:scba_1', 'number' => '2103']);

        $this->submit($unit, ['field:scba_1' => 'Inspected'], []);
        $this->assertDatabaseHas('apparatus_equipment_numbers', ['slot' => 'field:scba_1', 'number' => '2103']);
    }

    public function test_a_member_who_left_a_number_alone_does_not_undo_a_newer_change(): void
    {
        $unit = $this->unit('engine');
        $this->submit($unit, ['field:scba_1' => '2103', 'field:scba_2' => '2161'], []);
        // This member opened the checkout now and is slow to submit, or their phone is offline.
        $shown = $this->seen($unit);

        $this->submit($unit, ['field:scba_1' => '2200', 'field:scba_2' => '2161'], []);

        // They change only the second box. The first still holds what they were shown.
        $this->submit($unit, ['field:scba_1' => '2103', 'field:scba_2' => '2999'], [], $shown);

        $this->assertDatabaseHas('apparatus_equipment_numbers', ['slot' => 'field:scba_1', 'number' => '2200']);
        $this->assertDatabaseHas('apparatus_equipment_numbers', ['slot' => 'field:scba_2', 'number' => '2999']);
    }

    public function test_a_replayed_stale_submission_does_not_bring_back_a_cleared_number(): void
    {
        $unit = $this->unit('engine');
        $this->submit($unit, ['field:scba_1' => '2103', 'field:scba_2' => '2161'], []);
        $shown = $this->seen($unit);

        $this->submit($unit, ['field:scba_2' => '2161'], []);
        $this->assertDatabaseMissing('apparatus_equipment_numbers', ['slot' => 'field:scba_1']);

        $this->submit($unit, ['field:scba_1' => '2103', 'field:scba_2' => '2161'], [], $shown);
        $this->assertDatabaseMissing('apparatus_equipment_numbers', ['slot' => 'field:scba_1']);
    }

    public function test_an_extra_added_by_someone_else_survives_a_member_who_was_never_shown_it(): void
    {
        $unit = $this->unit('rescue');
        $mine = (string) Str::uuid();
        $this->submit($unit, [], [['client_id' => $mine, 'kind' => 'radio', 'label' => null, 'number' => '1800']]);
        $shown = $this->seen($unit);

        $theirs = (string) Str::uuid();
        $this->submit($unit, [], [
            ['client_id' => $mine, 'kind' => 'radio', 'label' => null, 'number' => '1801'],
            ['client_id' => $theirs, 'kind' => 'scba', 'label' => null, 'number' => '2200'],
        ]);

        // Stale list: they saw 1800, kept it, and know nothing of the new one.
        $this->submit($unit, [], [['client_id' => $mine, 'kind' => 'radio', 'label' => null, 'number' => '1800']], $shown);

        $extras = $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('equipment_numbers.extras');
        $this->assertEqualsCanonicalizing(['1801', '2200'], array_column($extras, 'number'));
    }

    public function test_a_stale_member_can_still_remove_the_extra_they_were_shown(): void
    {
        $unit = $this->unit('rescue');
        $mine = (string) Str::uuid();
        $this->submit($unit, [], [['client_id' => $mine, 'kind' => 'radio', 'label' => null, 'number' => '1800']]);
        $shown = $this->seen($unit);

        $theirs = (string) Str::uuid();
        $this->submit($unit, [], [
            ['client_id' => $mine, 'kind' => 'radio', 'label' => null, 'number' => '1800'],
            ['client_id' => $theirs, 'kind' => 'scba', 'label' => null, 'number' => '2200'],
        ]);

        $this->submit($unit, [], [], $shown);

        $extras = $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('equipment_numbers.extras');
        $this->assertSame(['2200'], array_column($extras, 'number'));
    }

    public function test_a_problem_saving_the_numbers_never_costs_the_member_their_checkout(): void
    {
        $unit = $this->unit('engine');
        Schema::drop('apparatus_equipment_numbers');

        $this->submit($unit, ['field:scba_1' => '2103'], null);

        $inspection = ApparatusInspection::query()->sole();
        $this->assertNotNull($inspection->processing_status);
    }

    public function test_numbers_stay_on_their_own_apparatus(): void
    {
        $one = $this->unit('engine');
        $two = $this->unit('engine');
        $this->submit($one, ['field:scba_1' => '2103'], []);

        $slots = collect($this->getJson("/api/public/apparatuses/{$two->id}/checklist")->json('equipment_numbers.slots'))->keyBy('slot');
        $this->assertSame('', $slots['field:scba_1']['number']);
    }

    public function test_the_numbers_do_not_change_the_checklist_version(): void
    {
        $unit = $this->unit('engine');
        $before = $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('checklist_version');
        $this->submit($unit, ['field:scba_1' => '2103'], []);

        $this->assertSame($before, $this->getJson("/api/public/apparatuses/{$unit->id}/checklist")->json('checklist_version'));
    }

    public function test_a_bad_added_number_is_refused_and_records_nothing(): void
    {
        $unit = $this->unit('engine');
        foreach ([
            'client_id' => [['client_id' => 'nope', 'kind' => 'radio', 'number' => '1']],
            'kind' => [['client_id' => (string) Str::uuid(), 'kind' => 'pager', 'number' => '1']],
            'number' => [['client_id' => (string) Str::uuid(), 'kind' => 'radio', 'number' => '   ']],
        ] + ['number ' => [['client_id' => (string) Str::uuid(), 'kind' => 'radio', 'number' => str_repeat('1', 65)]]] as $field => $extras) {
            $this->postJson("/api/public/apparatuses/{$unit->id}/inspections", $this->payload($unit, [], $extras))
                ->assertUnprocessable()->assertJsonValidationErrors('extra_equipment_numbers.0.'.trim($field));
        }
        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_equipment_numbers', 0);
    }

    /**
     * @param  array<string, string>  $numbers  slot => number
     * @param  list<array<string, mixed>>|null  $extras  null is an older app, which sends neither key
     * @param  list<array{slot: string, number: string}>|null  $shown  what the member saw; by default what is current
     */
    private function submit(Apparatus $unit, array $numbers, ?array $extras = [], ?array $shown = null): void
    {
        $unit->refresh();
        $this->shown = $extras === null ? null : ($shown ?? $this->seen($unit));
        if ($unit->daily_checkout_template?->value === 'fireboat6') {
            $start = $this->postJson("/api/public/apparatuses/{$unit->id}/inspection-sessions", ['inspection_session_start_key' => (string) Str::uuid()])->assertSuccessful();
            $cookie = collect($start->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'daily_checkout_inspection_browser');
            if ($cookie) {
                $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
            $contract = $start->json('inspection_session');
            $this->postJson("/api/public/apparatuses/{$unit->id}/inspections", [
                ...$this->payload($unit, $numbers, $extras, $contract['duty_date'], $contract['due_tasks']),
                'checklist_version' => $contract['checklist_hash'],
                'inspection_session_id' => $contract['id'],
                'inspection_session_token' => $contract['token'],
                'inspection_session_replay_key' => $contract['replay_key'],
            ])->assertCreated();

            return;
        }
        $this->postJson("/api/public/apparatuses/{$unit->id}/inspections", $this->payload($unit, $numbers, $extras))->assertCreated();
    }

    /**
     * What the app sends, with every write-in answered so the checklist's own rules are met.
     *
     * @param  array<string, string>  $numbers
     * @param  list<array<string, mixed>>|null  $extras
     * @param  list<array<string, mixed>>  $dueTasks
     * @return array<string, mixed>
     */
    private function payload(Apparatus $unit, array $numbers, ?array $extras, ?string $dutyDate = null, array $dueTasks = []): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($unit);
        $checklist = $resolution['checklist'];
        $slots = collect(app(CrewEquipmentNumbers::class)->slots($checklist))->keyBy('slot');

        return [
            'processing_version' => 1,
            'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => (string) $resolution['checklist_version'],
            'operator_name' => 'Firefighter Example',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => (string) $unit->vehicle_number,
            'engine_hours' => null,
            'miles' => null,
            'compartments' => array_map(static function (array $compartment) use ($numbers, $slots): array {
                return [
                    'id' => $compartment['id'],
                    'name' => $compartment['name'] ?? $compartment['title'],
                    'items' => array_map(static function (array $item, int $index) use ($compartment, $numbers, $slots): array {
                        $id = $item['id'] ?? $compartment['id'].'-item-'.($index + 1);
                        $key = 'item:'.$compartment['id'].'/'.$id;
                        $row = ['id' => $id, 'name' => $item['name'], 'status' => 'Present', 'observed' => true, 'notes' => null];
                        if (($item['inputType'] ?? 'checkbox') !== 'checkbox') {
                            $row['value'] = $numbers[$key] ?? ($slots->has($key) && ($item['valueRequired'] ?? true) === false ? null : (($item['inputType'] ?? '') === 'text' ? 'Recorded' : 1));
                        }

                        return $row;
                    }, $compartment['items'], array_keys($compartment['items'])),
                ];
            }, $checklist['compartments']),
            'defects' => [],
            'field_values' => array_map(fn (array $field): array => [
                'id' => $field['id'],
                'value' => $numbers['field:'.$field['id']] ?? match (true) {
                    $field['id'] === 'vehicle_num' => $unit->vehicle_number,
                    $field['id'] === 'mileage' => null,
                    $field['inputType'] === 'date' => $dutyDate ?? now('America/New_York')->toDateString(),
                    ($field['required'] ?? false) === false => null,
                    $field['inputType'] === 'checkbox' => true,
                    in_array($field['inputType'], ['number', 'percentage'], true) => 50,
                    default => 'Recorded',
                },
            ], $checklist['fields'] ?? $checklist['officerChecklist'] ?? []),
            'scheduled_tasks' => array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Completed', 'notes' => null, 'observed' => true], $dueTasks),
            'officer_signature' => self::SIGNATURE,
            ...($extras === null ? [] : ['extra_equipment_numbers' => $extras, 'equipment_numbers_seen' => $this->shown ?? []]),
        ];
    }

    /**
     * What the app reports having shown the member, from the block it was given.
     *
     * @return list<array{slot: string, number: string}>
     */
    private function seen(Apparatus $unit): array
    {
        $block = app(CrewEquipmentNumbers::class)->block($unit, $this->checklist($unit));

        return [
            ...collect($block['slots'])->where('number', '!=', '')->map(static fn (array $slot): array => ['slot' => $slot['slot'], 'number' => $slot['number']])->values()->all(),
            ...array_map(static fn (array $extra): array => ['slot' => 'extra:'.$extra['client_id'], 'number' => $extra['number']], $block['extras']),
        ];
    }

    /** @return array<string, mixed> */
    private function checklist(Apparatus $unit): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($unit);
        $this->assertTrue($resolution['usable'], (string) ($resolution['error'] ?? ''));

        return $resolution['checklist'];
    }

    private function unit(string $template): Apparatus
    {
        static $next = 0;
        $next++;
        $station = Station::query()->firstOrCreate(['station_number' => 6], ['name' => 'Station 6', 'address' => '1 Fixture Way', 'is_active' => true]);
        [$designation, $type, $unitId, $slug] = match ($template) {
            'engine' => ["E{$next}", 'Engine', "E{$next}", "e{$next}"],
            'engine2' => ['E2', 'Engine', 'E2', 'e2'],
            'ladder1' => ['L1', 'Ladder', 'L1', 'l1'],
            'ladder3' => ['L3', 'Ladder', 'L3', 'l3'],
            'rescue' => ["R{$next}", 'Rescue', "R{$next}", "r{$next}"],
            default => ['FB6', 'Fire Boat', 'FB6', 'fb6'],
        };

        return Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => $unitId, 'name' => $designation, 'designation' => $designation, 'type' => $type, 'slug' => $slug,
            'vehicle_number' => $template === 'fireboat6' ? null : (string) (20000 + $next),
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => $template,
        ]);
    }
}

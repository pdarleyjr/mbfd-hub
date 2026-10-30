<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Narcotics\NarcoticsNotifier;
use App\Support\Narcotics\RescueUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

/** The rescue unit a member names on a narcotics check (docs/narcotics-check.md section 1). */
final class NarcoticsCheckRescueUnitTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
    }

    /** @return array<string, array{string}> */
    public static function listedUnits(): array
    {
        $units = ['R1', 'R11', 'R2', 'R22', 'R3', 'R4', 'R44', 'FB6'];

        return array_combine($units, array_map(fn (string $unit): array => [$unit], $units));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('listedUnits')]
    public function test_every_listed_unit_is_accepted_and_shown_as_chosen(string $unit): void
    {
        $response = $this->sendCheck($this->member, null, ['rescue_unit' => $unit])
            ->assertStatus(201)
            ->assertJsonPath('data.rescue_unit', $unit)
            ->assertJsonPath('data.rescue_unit_choice', $unit)
            ->assertJsonPath('data.rescue_unit_other', null);

        $check = NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame($unit, $check->rescue_unit);
        $this->assertNull($check->rescue_unit_other);
    }

    public function test_the_form_endpoint_gives_the_one_server_list_with_other_last(): void
    {
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/form')
            ->assertOk()
            ->assertJsonPath('data.rescue_units', ['R1', 'R11', 'R2', 'R22', 'R3', 'R4', 'R44', 'FB6', 'Other'])
            ->assertJsonPath('data.rescue_unit_other_option', 'Other')
            ->assertJsonPath('data.rescue_unit_other_max_length', 40);

        // Changing the one server list changes what is offered and what is accepted.
        config()->set('narcotics_checks.rescue_units', ['R7']);
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/form')->assertJsonPath('data.rescue_units', ['R7', 'Other']);
        $this->sendCheck($this->member, null, ['rescue_unit' => 'R7'])->assertStatus(201);
        $this->sendCheck($this->member, null, ['rescue_unit' => 'R1'])->assertStatus(422);
    }

    public function test_a_missing_unit_is_refused_in_plain_words_and_nothing_is_stored(): void
    {
        $this->sendCheck($this->member, null, ['rescue_unit' => null])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Choose your rescue unit.')
            ->assertJsonPath('error.fields.rescue_unit.0', 'Choose your rescue unit.');
        $this->sendCheck($this->member, null, ['rescue_unit' => ''])->assertStatus(422)->assertJsonPath('error.fields.rescue_unit.0', 'Choose your rescue unit.');

        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidUnits(): array
    {
        return [
            'not on the list' => ['R5'],
            'wrong case' => ['r22'],
            'padded inside' => ['R 22'],
            'a number' => ['22'],
            'a longer name' => ['Rescue 22'],
            'a list' => [['R22']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUnits')]
    public function test_a_value_the_server_does_not_list_is_rejected_whatever_the_browser_sent(mixed $value): void
    {
        $this->sendCheck($this->member, null, ['rescue_unit' => $value])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['rescue_unit']]]);

        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_other_asks_for_text(): void
    {
        foreach ([null, '', '   ', "\u{00A0}\u{00A0}"] as $blank) {
            $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => $blank])
                ->assertStatus(422)
                ->assertJsonPath('error.fields.rescue_unit_other.0', 'Enter which rescue unit you are on.');
        }

        $this->assertSame(0, NarcoticsCheck::query()->count());
    }

    public function test_other_text_is_trimmed_and_stored_as_given(): void
    {
        $response = $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => "  \u{00A0}Rescue 7 (spare) \u{00A0} "])
            ->assertStatus(201)
            ->assertJsonPath('data.rescue_unit', 'Rescue 7 (spare)')
            ->assertJsonPath('data.rescue_unit_choice', 'Other')
            ->assertJsonPath('data.rescue_unit_other', 'Rescue 7 (spare)');

        $check = NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame('Other', $check->rescue_unit);
        $this->assertSame('Rescue 7 (spare)', $check->rescue_unit_other);
        $this->assertSame('Rescue 7 (spare)', $check->rescueUnitLabel());

        // It is stored as typed, not upper-cased or reshaped, and text that looks like a listed unit is still "Other".
        $again = $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => 'r5-Spare'])->assertStatus(201);
        $this->assertSame('r5-Spare', $again->json('data.rescue_unit'));
    }

    public function test_other_text_is_limited_to_40_characters(): void
    {
        $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => str_repeat('a', 40)])->assertStatus(201);
        $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => str_repeat('é', 40)])->assertStatus(201);

        $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => str_repeat('a', 41)])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.rescue_unit_other.0', 'The rescue unit can be at most 40 characters.');
        // Spaces around 40 real characters do not count against the limit.
        $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => '  '.str_repeat('b', 40).'  '])->assertStatus(201);

        $this->assertSame(3, NarcoticsCheck::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function unsafeText(): array
    {
        return [
            'a line break' => ["R5\nR6"],
            'a carriage return' => ["R5\rR6"],
            'a tab' => ["R5\tR6"],
            'a null byte' => ["R\0005"],
            'a zero-width space' => ["R\u{200B}5"],
            'a bidirectional override' => ["R5\u{202E}"],
            'a line separator' => ["R5\u{2028}R6"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeText')]
    public function test_other_text_must_be_a_single_plain_line(string $text): void
    {
        $this->sendCheck($this->member, null, ['rescue_unit' => 'Other', 'rescue_unit_other' => $text])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.rescue_unit_other.0', 'The rescue unit must be on one line, without special characters.');
        $this->assertSame(0, NarcoticsCheck::query()->count());
    }

    public function test_free_text_is_ignored_and_not_stored_when_a_listed_unit_is_chosen(): void
    {
        foreach (['Some leftover text', "line one\nline two", str_repeat('x', 400)] as $leftover) {
            $response = $this->sendCheck($this->member, null, ['rescue_unit' => 'R22', 'rescue_unit_other' => $leftover])
                ->assertStatus(201)
                ->assertJsonPath('data.rescue_unit', 'R22')
                ->assertJsonPath('data.rescue_unit_other', null);

            $this->assertNull(NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->value('rescue_unit_other'));
        }
    }

    public function test_the_unit_is_shown_to_the_member_and_to_captain_5(): void
    {
        $listed = $this->submitOk($this->member, ['rescue_unit' => 'R44']);
        $other = $this->submitOk($this->member, ['rescue_unit' => 'Other', 'rescue_unit_other' => 'Rescue 9']);

        $mine = collect($this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('R44', $mine[$listed->uuid]['rescue_unit']);
        $this->assertSame('Rescue 9', $mine[$other->uuid]['rescue_unit']);
        $this->assertSame('Other', $mine[$other->uuid]['rescue_unit_choice']);
        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$listed->uuid}")->assertOk()->assertJsonPath('data.rescue_unit', 'R44');

        $inbox = collect($this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('R44', $inbox[$listed->uuid]['rescue_unit']);
        $this->assertSame('Rescue 9', $inbox[$other->uuid]['rescue_unit']);
        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$other->uuid}")->assertOk()->assertJsonPath('data.rescue_unit', 'Rescue 9');

        $this->approve($this->captain5, $listed)->assertOk()->assertJsonPath('data.rescue_unit', 'R44');
        $archive = $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive')->assertOk();
        $this->assertSame('R44', $archive->json('data.0.rescue_unit'));
    }

    public function test_checks_sent_before_units_existed_still_display_without_one(): void
    {
        $old = $this->submitOk($this->member);
        DB::table('narcotics_checks')->where('id', $old->id)->update(['rescue_unit' => null, 'rescue_unit_other' => null]);

        $this->assertNull($old->fresh()->rescueUnitLabel());
        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$old->uuid}")
            ->assertOk()
            ->assertJsonPath('data.rescue_unit', null)
            ->assertJsonPath('data.rescue_unit_choice', null)
            ->assertJsonPath('data.rescue_unit_other', null);
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->assertOk()->assertJsonPath('data.0.rescue_unit', null);
        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertOk()->assertJsonPath('data.0.rescue_unit', null);
        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$old->uuid}")->assertOk()->assertJsonPath('data.rescue_unit', null);

        // Deciding an old check still works and its notice just has no unit in it.
        $this->approve($this->captain5, $old)->assertOk();
    }

    public function test_the_audit_snapshot_records_the_unit(): void
    {
        $check = $this->submitOk($this->member, ['rescue_unit' => 'Other', 'rescue_unit_other' => 'Rescue 9']);

        $event = NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->where('action', 'submitted')->sole();
        $this->assertSame(['choice' => 'Other', 'other' => 'Rescue 9', 'label' => 'Rescue 9'], $event->payload['rescue_unit']);
    }

    public function test_a_resubmission_must_carry_a_unit_and_may_change_it(): void
    {
        $rejected = $this->submitOk($this->member, ['rescue_unit' => 'R22']);
        $this->reject($this->captain5, $rejected, 'Blurry')->assertOk();

        $this->sendCheck($this->member, null, ['resubmission_of' => $rejected->uuid, 'rescue_unit' => null])
            ->assertStatus(422)->assertJsonPath('error.fields.rescue_unit.0', 'Choose your rescue unit.');

        $again = $this->submitOk($this->member, ['resubmission_of' => $rejected->uuid, 'rescue_unit' => 'R2']);
        $this->assertSame('R2', $again->rescue_unit);
        $this->assertSame('R22', $rejected->fresh()->rescue_unit, 'the rejected record is never edited');

        // The rejected check hands back what the form needs to prefill the answer.
        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$rejected->uuid}")
            ->assertOk()->assertJsonPath('data.rescue_unit_choice', 'R22')->assertJsonPath('data.can_resubmit', false);
    }

    public function test_a_retried_submission_with_the_same_id_is_replayed_with_its_unit(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $first = $this->sendCheck($this->member, null, ['client_submission_id' => $id, 'rescue_unit' => 'R3'])->assertStatus(201);
        $second = $this->sendCheck($this->member, null, ['client_submission_id' => $id, 'rescue_unit' => 'R3'])
            ->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.rescue_unit', 'R3');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, NarcoticsCheck::query()->count());
    }

    public function test_captain_5_can_search_the_archive_by_unit(): void
    {
        $r22 = $this->submitOk($this->member, ['rescue_unit' => 'R22']);
        $other = $this->submitOk($this->member, ['rescue_unit' => 'Other', 'rescue_unit_other' => 'Spare Rescue 12']);
        $r1 = $this->submitOk($this->member, ['rescue_unit' => 'R1']);
        foreach ([$r22, $other, $r1] as $check) {
            $this->approve($this->captain5, $check)->assertOk();
        }

        $found = fn (string $term): array => $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&q='.rawurlencode($term))->assertOk()->json('data.*.id');
        $this->assertSame([$r22->uuid], $found('r22'));
        $this->assertSame([$other->uuid], $found('spare rescue'));
    }

    public function test_searching_for_r2_finds_r2_and_never_r22(): void
    {
        $r2 = $this->submitOk($this->member, ['rescue_unit' => 'R2']);
        $r22 = $this->submitOk($this->member, ['rescue_unit' => 'R22']);
        $r1 = $this->submitOk($this->member, ['rescue_unit' => 'R1']);
        $r11 = $this->submitOk($this->member, ['rescue_unit' => 'R11']);
        foreach ([$r2, $r22, $r1, $r11] as $check) {
            $this->approve($this->captain5, $check)->assertOk();
        }

        $found = fn (string $term): array => collect($this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&q='.rawurlencode($term))->assertOk()->json('data.*.id'))->sort()->values()->all();
        $this->assertSame([$r2->uuid], $found('R2'));
        $this->assertSame([$r2->uuid], $found('r2'));
        $this->assertSame([$r2->uuid], $found(' R 2 '), 'spaces and case do not change which unit is meant');
        $this->assertSame([$r22->uuid], $found('R22'));
        $this->assertSame([$r1->uuid], $found('R1'));
        $this->assertSame([$r11->uuid], $found('R11'));
        $this->assertSame(collect([$r1, $r11, $r2, $r22])->pluck('uuid')->sort()->values()->all(), $found('R'), 'a partial word still searches by text');
    }

    public function test_the_in_app_notice_names_the_unit_but_the_lock_screen_and_email_do_not(): void
    {
        $this->submitOk($this->member, ['rescue_unit' => 'R22']);
        $notice = Notification::sent($this->captain5, InspectionEventNotification::class)
            ->first(fn (InspectionEventNotification $n): bool => $n->kind === NarcoticsNotifier::KIND_SUBMITTED);

        $this->assertSame('Pat Member sent a narcotics check (R22). Open Captain 5 to review it.', $notice->body);
        $this->assertSame(['title' => 'MBFD Hub update', 'body' => 'Open MBFD Hub for details.'], $notice->pushText());
        $email = $notice->toBudgetedEmail($this->captain5);
        foreach (['text', 'html'] as $part) {
            $this->assertStringNotContainsString('R22', $email[$part]);
        }
    }

    public function test_the_in_app_notice_for_a_second_try_names_the_unit_and_says_so(): void
    {
        $rejected = $this->submitOk($this->member, ['rescue_unit' => 'R4']);
        $this->reject($this->captain5, $rejected)->assertOk();
        $again = $this->submitOk($this->member, ['resubmission_of' => $rejected->uuid, 'rescue_unit' => 'Other', 'rescue_unit_other' => 'Rescue 9']);

        $notice = Notification::sent($this->captain5, InspectionEventNotification::class)
            ->first(fn (InspectionEventNotification $n): bool => $n->eventKey === 'narcotics:check:'.$again->id.':submitted');
        $this->assertSame('Pat Member sent a narcotics check (Rescue 9, sent again after a rejection). Open Captain 5 to review it.', $notice->body);
    }

    public function test_the_unit_helper_reads_the_one_list(): void
    {
        $this->assertSame(['R1', 'R11', 'R2', 'R22', 'R3', 'R4', 'R44', 'FB6', 'Other'], RescueUnits::options());
        $this->assertSame('Other', RescueUnits::other());
        $this->assertSame(40, RescueUnits::maxOtherLength());
        $this->assertNull(RescueUnits::label(null, null));
        $this->assertNull(RescueUnits::label('', 'x'));
        $this->assertSame('R22', RescueUnits::label('R22', 'ignored'));
        $this->assertSame('Rescue 9', RescueUnits::label('Other', ' Rescue 9 '));
        $this->assertSame('Other', RescueUnits::label('Other', null), 'a row that somehow lacks the text still says what was chosen');
    }

    public function test_the_unit_migration_is_additive_nullable_and_safe_to_run_twice(): void
    {
        $check = $this->submitOk($this->member, ['rescue_unit' => 'R3']);

        (require database_path('migrations/2026_10_30_170000_add_rescue_unit_to_narcotics_checks.php'))->up();

        $this->assertTrue(Schema::hasColumns('narcotics_checks', ['rescue_unit', 'rescue_unit_other']));
        $this->assertSame('R3', $check->fresh()->rescue_unit, 'running it again changes no data');
        $columns = collect(Schema::getColumns('narcotics_checks'))->keyBy('name');
        $this->assertTrue((bool) $columns['rescue_unit']['nullable']);
        $this->assertTrue((bool) $columns['rescue_unit_other']['nullable']);
    }
}

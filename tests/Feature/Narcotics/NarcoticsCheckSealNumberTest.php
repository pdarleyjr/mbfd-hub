<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Narcotics\NarcoticsNotifier;
use App\Support\Narcotics\SealNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

/** The seal number a member types on a narcotics check (docs/narcotics-check.md section 1). */
final class NarcoticsCheckSealNumberTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
    }

    /** @return array<string, array{string}> */
    public static function acceptedSeals(): array
    {
        return [
            'digits' => ['00481726'],
            'letters and a dash' => ['SEAL-0001'],
            'with spaces' => ['A 12 B-7'],
            'lower case stays lower case' => ['ab-12c'],
            'punctuation' => ['NC/2026#14'],
            'accents' => ['Séal-é9'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedSeals')]
    public function test_a_seal_number_is_accepted_stored_once_and_shown_exactly_as_typed(string $seal): void
    {
        $response = $this->sendCheck($this->member, null, ['seal_number' => $seal])
            ->assertStatus(201)
            ->assertJsonPath('data.seal_number', $seal);

        $this->assertSame(1, NarcoticsCheck::query()->count());
        $this->assertSame($seal, NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->value('seal_number'));
    }

    public function test_the_form_endpoint_tells_the_browser_the_length_so_it_is_not_hard_coded(): void
    {
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/form')->assertOk()->assertJsonPath('data.seal_number_max_length', 40);

        config()->set('narcotics_checks.seal_number_max_length', 12);
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/form')->assertJsonPath('data.seal_number_max_length', 12);
        $this->sendCheck($this->member, null, ['seal_number' => str_repeat('9', 12)])->assertStatus(201);
        $this->sendCheck($this->member, null, ['seal_number' => str_repeat('9', 13)])->assertStatus(422);
    }

    public function test_a_missing_seal_number_is_refused_in_plain_words_and_nothing_is_stored(): void
    {
        foreach ([null, '', '   ', "\u{00A0}\u{00A0}"] as $blank) {
            $this->sendCheck($this->member, null, ['seal_number' => $blank])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath('error.message', 'Enter the seal number.')
                ->assertJsonPath('error.fields.seal_number.0', 'Enter the seal number.');
        }

        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_seal_number_is_trimmed_including_no_break_spaces(): void
    {
        $this->sendCheck($this->member, null, ['seal_number' => "  \u{00A0}SEAL 77 \u{00A0} "])
            ->assertStatus(201)
            ->assertJsonPath('data.seal_number', 'SEAL 77');
    }

    public function test_the_seal_number_is_limited_to_40_characters(): void
    {
        $this->sendCheck($this->member, null, ['seal_number' => str_repeat('a', 40)])->assertStatus(201);
        $this->sendCheck($this->member, null, ['seal_number' => str_repeat('é', 40)])->assertStatus(201);
        $this->sendCheck($this->member, null, ['seal_number' => '  '.str_repeat('b', 40).'  '])->assertStatus(201);

        $this->sendCheck($this->member, null, ['seal_number' => str_repeat('a', 41)])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.seal_number.0', 'The seal number can be at most 40 characters.');
        $this->assertSame(3, NarcoticsCheck::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function unsafeSeals(): array
    {
        return [
            'a line break' => ["12\n34"],
            'a carriage return' => ["12\r34"],
            'a tab' => ["12\t34"],
            'a null byte' => ["12\0034"],
            'a zero-width space' => ["12\u{200B}34"],
            'a bidirectional override' => ["1234\u{202E}"],
            'a line separator' => ["12\u{2028}34"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeSeals')]
    public function test_a_multi_line_or_control_character_seal_number_is_rejected(string $seal): void
    {
        $this->sendCheck($this->member, null, ['seal_number' => $seal])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.seal_number.0', 'The seal number must be on one line, without special characters.');
        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_list_is_not_text(): void
    {
        $this->sendCheck($this->member, null, ['seal_number' => ['1234']])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['seal_number']]]);
        $this->assertSame(0, NarcoticsCheck::query()->count());
    }

    public function test_it_is_shown_to_the_member_and_to_captain_5_and_found_by_the_archive_search(): void
    {
        $first = $this->submitOk($this->member, ['seal_number' => 'ZX-4471']);
        $second = $this->submitOk($this->member, ['seal_number' => 'QQ-9000']);

        $mine = collect($this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('ZX-4471', $mine[$first->uuid]['seal_number']);
        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$second->uuid}")->assertOk()->assertJsonPath('data.seal_number', 'QQ-9000');

        $inbox = collect($this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('ZX-4471', $inbox[$first->uuid]['seal_number']);
        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$second->uuid}")->assertOk()->assertJsonPath('data.seal_number', 'QQ-9000');

        $this->approve($this->captain5, $first)->assertOk()->assertJsonPath('data.seal_number', 'ZX-4471');
        $this->approve($this->captain5, $second)->assertOk();
        $found = $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&q='.rawurlencode('zx-44'))->assertOk()->json('data.*.id');
        $this->assertSame([$first->uuid], $found);
    }

    public function test_checks_sent_before_seal_numbers_existed_still_display_without_one(): void
    {
        $old = $this->submitOk($this->member);
        DB::table('narcotics_checks')->where('id', $old->id)->update(['seal_number' => null]);

        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$old->uuid}")->assertOk()->assertJsonPath('data.seal_number', null);
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->assertOk()->assertJsonPath('data.0.seal_number', null);
        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertOk()->assertJsonPath('data.0.seal_number', null);
        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$old->uuid}")->assertOk()->assertJsonPath('data.seal_number', null);
        $this->approve($this->captain5, $old)->assertOk();
    }

    public function test_the_audit_snapshot_records_it(): void
    {
        $check = $this->submitOk($this->member, ['seal_number' => 'ZX-4471']);

        $event = NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->where('action', 'submitted')->sole();
        $this->assertSame('ZX-4471', $event->payload['seal_number']);
    }

    public function test_a_resubmission_must_carry_a_seal_number_and_may_change_it(): void
    {
        $rejected = $this->submitOk($this->member, ['seal_number' => 'OLD-1']);
        $this->reject($this->captain5, $rejected, 'Blurry')->assertOk();

        $this->sendCheck($this->member, null, ['resubmission_of' => $rejected->uuid, 'seal_number' => null])
            ->assertStatus(422)->assertJsonPath('error.fields.seal_number.0', 'Enter the seal number.');

        $again = $this->submitOk($this->member, ['resubmission_of' => $rejected->uuid, 'seal_number' => 'NEW-2']);
        $this->assertSame('NEW-2', $again->seal_number);
        $this->assertSame('OLD-1', $rejected->fresh()->seal_number, 'the rejected record is never edited');
        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$rejected->uuid}")->assertOk()->assertJsonPath('data.seal_number', 'OLD-1');
    }

    public function test_a_retried_submission_is_one_check_with_one_seal_number(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $first = $this->sendCheck($this->member, null, ['client_submission_id' => $id, 'seal_number' => 'ZX-4471'])->assertStatus(201);
        $second = $this->sendCheck($this->member, null, ['client_submission_id' => $id, 'seal_number' => 'ZX-4471'])
            ->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.seal_number', 'ZX-4471');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, NarcoticsCheck::query()->count());
    }

    public function test_no_notification_carries_the_seal_number(): void
    {
        $check = $this->submitOk($this->member, ['seal_number' => 'ZX-4471-SECRET']);
        $this->approve($this->captain5, $check)->assertOk();

        $all = Notification::sent($this->captain5, InspectionEventNotification::class)->merge(Notification::sent($this->member, InspectionEventNotification::class));
        $this->assertCount(2, $all);
        foreach ($all as $notification) {
            $this->assertStringNotContainsString('ZX-4471', $notification->title.' | '.$notification->body.' | '.$notification->url);
            $this->assertSame(['title' => 'MBFD Hub update', 'body' => 'Open MBFD Hub for details.'], $notification->pushText());
        }
        $submitted = Notification::sent($this->captain5, InspectionEventNotification::class)->first(fn (InspectionEventNotification $n): bool => $n->kind === NarcoticsNotifier::KIND_SUBMITTED);
        $this->assertSame('Pat Member sent a narcotics check (R1). Open Captain 5 to review it.', $submitted->body);
        $email = $submitted->toBudgetedEmail($this->captain5);
        foreach (['text', 'html'] as $part) {
            $this->assertStringNotContainsString('ZX-4471', $email[$part]);
        }
    }

    public function test_the_helper_reads_the_one_setting(): void
    {
        $this->assertSame(40, SealNumber::maxLength());
        $this->assertSame(['seal_number' => '1234'], SealNumber::prepare(['seal_number' => ' 1234 ']));
        $this->assertSame(['seal_number' => ['1234']], SealNumber::prepare(['seal_number' => ['1234']]));
    }

    public function test_the_migration_is_additive_nullable_and_safe_to_run_twice(): void
    {
        $check = $this->submitOk($this->member, ['seal_number' => 'ZX-4471']);

        (require database_path('migrations/2026_10_30_180000_add_seal_number_to_narcotics_checks.php'))->up();

        $this->assertTrue(Schema::hasColumn('narcotics_checks', 'seal_number'));
        $this->assertSame('ZX-4471', $check->fresh()->seal_number, 'running it again changes no data');
        $this->assertTrue((bool) collect(Schema::getColumns('narcotics_checks'))->keyBy('name')['seal_number']['nullable']);
    }
}

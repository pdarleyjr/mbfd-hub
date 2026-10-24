<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * "Every Daily Checkout as it's submitted": the old per-checkout alert, back as an OPT-IN category.
 * Nobody gets it by default, only current reviewers can receive it, and a saved row alone never grants it.
 */
final class EveryDailyCheckoutAlertTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private const KEY = 'daily_checkout_every_submission';

    private function optIn(User $user, bool $database = true, bool $push = false, bool $email = false, string $key = self::KEY): void
    {
        $user->notificationSubscriptions()->updateOrCreate(['event_key' => $key], [
            'database_enabled' => $database, 'webpush_enabled' => $push, 'email_enabled' => $email,
        ]);
    }

    private function submit(?User $by = null): ApparatusInspection
    {
        return $this->inspectionBy($this->rig(), $by ?? $this->member('Riley Reporter'));
    }

    public function test_the_category_is_opt_in_with_every_channel_off_and_reviewers_only(): void
    {
        $category = app(NotificationCategoryRegistry::class)->get(self::KEY);

        $this->assertNotNull($category);
        $this->assertSame("Every Daily Checkout as it's submitted", $category->label);
        $this->assertSame(['database' => false, 'webpush' => false, 'email' => false], $category->defaults);
        $this->assertSame([], $category->locked, 'nothing is forced on');
        $this->assertSame('routine', $category->significance);
        $this->assertSame(['database', 'webpush', 'email'], $category->channels);

        $registry = app(NotificationCategoryRegistry::class);
        $this->assertTrue($registry->forUser($this->reviewer())->has(self::KEY), 'a reviewer can find the switch');
        $this->assertFalse($registry->forUser($this->member('Pat Member'))->has(self::KEY), 'a member never sees it');
    }

    public function test_an_opted_in_reviewer_is_told_of_a_new_checkout(): void
    {
        $reviewer = $this->reviewer();
        $this->optIn($reviewer);

        $inspection = $this->submit();

        $alert = $reviewer->notifications()->sole();
        $this->assertSame('New Daily Checkout', $alert->data['title']);
        $this->assertStringContainsString('E2', $alert->data['body']);
        $this->assertSame(self::KEY, $alert->data['provenance']['category']);
        // Where the link goes is NotificationLink's call (it falls back to the account page for anyone who cannot open the inspection).
        $this->assertNotEmpty($alert->data['actions'][0]['url']);
        $this->assertGreaterThan(0, $inspection->id);
    }

    public function test_an_eligible_reviewer_who_did_not_opt_in_is_not_told(): void
    {
        $noRow = $this->reviewer();
        $allOff = $this->reviewer();
        $this->optIn($allOff, false, false, false);
        // The OLD category's saved choice no longer means "every checkout"; only the new category does.
        $oldChoiceOnly = $this->reviewer();
        $this->optIn($oldChoiceOnly, true, true, true, 'vehicle_inspections');

        $this->submit();

        foreach ([$noRow, $allOff, $oldChoiceOnly] as $person) {
            $this->assertSame(0, $person->notifications()->count());
        }
    }

    public function test_an_opted_in_person_who_is_no_longer_a_reviewer_is_not_told(): void
    {
        $formerReviewer = $this->reviewer();
        $this->optIn($formerReviewer);
        $formerReviewer->syncRoles([]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $member = $this->member('Casey Member');
        $this->optIn($member);

        $this->submit();

        $this->assertSame(0, $formerReviewer->fresh()->notifications()->count(), 'a saved row never grants access');
        $this->assertSame(0, $member->notifications()->count());
        $decision = app(NotificationRecipientEvaluator::class)->decide($formerReviewer->fresh(), self::KEY);
        $this->assertFalse($decision->eligible);
    }

    public function test_a_disabled_account_is_not_told(): void
    {
        $reviewer = $this->reviewer();
        $this->optIn($reviewer);
        $reviewer->forceFill(['account_status' => \App\Enums\AccountStatus::Disabled])->save();

        $this->submit();

        $this->assertSame(0, $reviewer->notifications()->count());
    }

    public function test_the_submitter_is_not_told_about_their_own_checkout(): void
    {
        $reviewer = $this->reviewer();
        $this->optIn($reviewer);
        $other = $this->reviewer();
        $this->optIn($other);

        $this->submit($reviewer);

        $this->assertSame(0, $reviewer->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());
    }

    public function test_an_excluded_test_checkout_alerts_nobody(): void
    {
        $reviewer = $this->reviewer();
        $this->optIn($reviewer);
        $rig = $this->rig();

        ApparatusInspection::query()->forceCreate([
            'apparatus_id' => $rig->id,
            'processed_apparatus_id' => $rig->id,
            'operator_name' => 'Test Tester',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $rig->designation,
            'designation_at_time' => $rig->designation,
            'inspection_reference' => 'INS-TEST-EXCLUDED',
            'review_status' => 'approved',
            'processing_status' => 'accepted',
            'results' => [],
            'completed_at' => now(),
            'test_excluded_at' => now(),
            'test_exclusion_reason' => 'Training submission',
        ]);

        $this->assertSame(0, $reviewer->notifications()->count());
    }

    public function test_only_the_channels_the_person_chose_and_can_receive_are_used(): void
    {
        $evaluator = app(NotificationRecipientEvaluator::class);
        $chose = $this->reviewer();
        $this->optIn($chose, true, true, true);

        $decision = $evaluator->decide($chose, self::KEY);

        $this->assertTrue($decision->eligible);
        $this->assertSame(['database'], $decision->channels, 'push needs a healthy device and email needs a city address');
        $this->assertSame([], $evaluator->decide($this->reviewer(), self::KEY)->channels, 'no row means nothing is on');
    }

    // ------------------------------------------------------------------ data migration

    private function copyChoices(): void
    {
        $migration = require base_path('database/migrations/2026_10_24_100000_copy_vehicle_inspection_alert_choices_to_every_submission.php');
        $migration->up();
    }

    /** @return array<int, object> keyed by user id */
    private function rows(string $key): array
    {
        return DB::table('user_notification_subscriptions')->where('event_key', $key)->get()->keyBy('user_id')->all();
    }

    public function test_the_migration_copies_each_chosen_old_row_and_nothing_else_and_is_idempotent(): void
    {
        [$all, $pushOnly, $emailOnly, $off, $none, $already] = [
            $this->reviewer(), $this->reviewer(), $this->reviewer(), $this->reviewer(), $this->reviewer(), $this->reviewer(),
        ];
        $this->optIn($all, true, true, true, 'vehicle_inspections');
        $this->optIn($pushOnly, false, true, false, 'vehicle_inspections');
        $this->optIn($emailOnly, false, false, true, 'vehicle_inspections');
        $this->optIn($off, false, false, false, 'vehicle_inspections');
        $this->optIn($already, true, true, true, 'vehicle_inspections');
        // Someone who already made a choice about the new category keeps it exactly.
        $this->optIn($already, false, false, true, self::KEY);
        DB::table('user_notification_subscriptions')->where('user_id', $already->id)->where('event_key', self::KEY)
            ->update(['created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00']);
        $before = $this->rows('vehicle_inspections');

        $this->copyChoices();
        $this->copyChoices();

        $copied = $this->rows(self::KEY);
        $this->assertSame([$all->id, $pushOnly->id, $emailOnly->id, $already->id], collect(array_keys($copied))->sort()->values()->all());
        $this->assertSame([true, true, true], [(bool) $copied[$all->id]->database_enabled, (bool) $copied[$all->id]->webpush_enabled, (bool) $copied[$all->id]->email_enabled]);
        $this->assertSame([false, true, false], [(bool) $copied[$pushOnly->id]->database_enabled, (bool) $copied[$pushOnly->id]->webpush_enabled, (bool) $copied[$pushOnly->id]->email_enabled]);
        $this->assertSame([false, false, true], [(bool) $copied[$emailOnly->id]->database_enabled, (bool) $copied[$emailOnly->id]->webpush_enabled, (bool) $copied[$emailOnly->id]->email_enabled]);
        $this->assertArrayNotHasKey($off->id, $copied, 'all-off is not a choice to be told');
        $this->assertArrayNotHasKey($none->id, $copied);
        $this->assertSame([false, false, true], [(bool) $copied[$already->id]->database_enabled, (bool) $copied[$already->id]->webpush_enabled, (bool) $copied[$already->id]->email_enabled], 'never overwritten');
        $this->assertSame('2026-10-02 00:00:00', (string) $copied[$already->id]->updated_at);
        $this->assertEquals($before, $this->rows('vehicle_inspections'), 'the old rows are untouched');
        $this->assertSame(4, DB::table('user_notification_subscriptions')->where('event_key', self::KEY)->count(), 'a second run adds nothing');
    }

    public function test_a_person_who_chose_the_old_alert_keeps_receiving_it_after_the_copy(): void
    {
        $chose = $this->reviewer();
        $this->optIn($chose, true, false, false, 'vehicle_inspections');
        $this->copyChoices();

        $this->submit();

        $this->assertSame(1, $chose->notifications()->count());
    }

    public function test_the_migration_can_be_rolled_back_without_touching_anyones_choices(): void
    {
        $user = $this->reviewer();
        $this->optIn($user, true, false, false, 'vehicle_inspections');
        $this->copyChoices();
        $migration = require base_path('database/migrations/2026_10_24_100000_copy_vehicle_inspection_alert_choices_to_every_submission.php');

        $migration->down();

        $this->assertCount(1, $this->rows(self::KEY), 'down() is deliberately a no-op');
        $this->assertCount(1, $this->rows('vehicle_inspections'));
    }
}

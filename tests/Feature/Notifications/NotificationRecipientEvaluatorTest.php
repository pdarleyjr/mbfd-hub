<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\Notifications\EligibilityContext;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDecision;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

final class NotificationRecipientEvaluatorTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private NotificationRecipientEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
        $this->evaluator = app(NotificationRecipientEvaluator::class);
    }

    private function stationReviewer(?string $cityEmail = null): User
    {
        return $this->withPermissions($this->rosterMember('Firefighter', $cityEmail), 'admin.access', 'admin.stations.view');
    }

    private function row(User $user, string $key, bool $database, bool $push, bool $mail): void
    {
        $user->notificationSubscriptions()->updateOrCreate(['event_key' => $key], [
            'database_enabled' => $database, 'webpush_enabled' => $push, 'email_enabled' => $mail,
        ]);
    }

    public function test_every_stored_preference_key_still_exists_in_the_registry_except_the_dead_one(): void
    {
        $keys = app(NotificationCategoryRegistry::class)->keys();

        foreach (array_keys(User::notificationPreferenceDefinitions()) as $key) {
            $this->assertContains($key, $keys);
        }
        foreach ([
            'vehicle_inspections', 'station_inspections', 'station_requests', 'apparatus_service_tickets', 'workgroup_evaluations',
            'station_inventory_alerts', 'department_updates', 'hub_support_tickets', 'uniform_requests', 'personnel_equipment_requests',
            'member_request_updates', 'vehicle_swap_reports', 'vehicle_identification_reports',
        ] as $existing) {
            $this->assertContains($existing, $keys, "{$existing} keeps its meaning for existing rows");
        }
        $this->assertNotContains('fire_equipment_requests', $keys, 'no sender ever existed for it');
    }

    public function test_every_category_describes_itself_in_plain_words_and_declares_valid_channels(): void
    {
        foreach (app(NotificationCategoryRegistry::class)->all() as $category) {
            $this->assertNotSame('', trim($category->label));
            $this->assertGreaterThan(20, strlen($category->description), $category->key.' needs a one-line description');
            $this->assertNotEmpty($category->channels);
            $this->assertSame([], array_diff($category->channels, NotificationCategory::ALL_CHANNELS));
            $this->assertSame([], array_diff($category->locked, $category->channels), 'only supported channels can be locked');
            $this->assertSame(NotificationCategory::ALL_CHANNELS, array_keys($category->defaults));
        }
    }

    public function test_routine_high_volume_categories_default_to_in_app_only(): void
    {
        $registry = app(NotificationCategoryRegistry::class);

        foreach (['vehicle_inspections', 'station_inspections', 'station_requests', 'apparatus_service_tickets', 'station_inventory_alerts', 'workgroup_evaluations'] as $key) {
            $this->assertSame(['database' => true, 'webpush' => false, 'email' => false], $registry->get($key)->defaults, $key);
        }
        $this->assertTrue($registry->get('department_updates')->defaults['webpush'], 'department notices keep their existing push default');
        $this->assertSame(['database' => true, 'webpush' => false, 'email' => true], $registry->get('vehicle_swap_reports')->defaults);
        $this->assertSame(['database' => true, 'webpush' => false, 'email' => true], $registry->get('vehicle_identification_reports')->defaults);
        $this->assertSame(['database' => false, 'webpush' => false, 'email' => false], $registry->get('hub_support_tickets')->defaults, 'opt-in only');
    }

    public function test_an_unknown_category_and_an_inactive_account_get_a_machine_reason(): void
    {
        $this->assertSame(NotificationDecision::UNKNOWN_CATEGORY, $this->evaluator->decide($this->member(), 'nope')->denial);

        $pending = $this->withPermissions(User::factory()->create(), 'admin.access', 'admin.stations.view');
        $decision = $this->evaluator->decide($pending, 'station_requests');
        $this->assertFalse($decision->eligible);
        $this->assertSame(NotificationDecision::ACCOUNT_INACTIVE, $decision->denial);
        $this->assertSame([], $decision->channels);
    }

    public function test_an_ineligible_member_is_denied_even_with_every_channel_saved_on(): void
    {
        $member = $this->member();
        $this->row($member, 'station_requests', true, true, true);
        $this->addPushDevice($member);

        $decision = $this->evaluator->decide($member, 'station_requests');

        $this->assertFalse($decision->eligible);
        $this->assertSame(NotificationDecision::NOT_ELIGIBLE, $decision->denial);
        $this->assertSame([], $decision->laravelChannels());
    }

    public function test_the_matched_rule_is_reported_for_provenance(): void
    {
        $this->assertSame('permission:admin.stations.view', $this->evaluator->decide($this->stationReviewer(), 'station_requests')->rule);
        $this->assertSame('active_member', $this->evaluator->decide($this->member(), 'department_updates')->rule);
        $this->assertSame('inspection_reviewer', $this->evaluator->decide($this->withRole($this->member(), 'logistics_admin'), 'vehicle_inspections')->rule);
    }

    public function test_channels_follow_the_saved_row_then_reachability(): void
    {
        $reviewer = $this->stationReviewer('reviewer@miamibeachfl.gov');
        $this->row($reviewer, 'station_requests', true, true, true);

        $noDevice = $this->evaluator->decide($reviewer, 'station_requests');
        $this->assertSame(['database', 'email'], $noDevice->channels);
        $this->assertSame(NotificationDecision::NO_DEVICE, $noDevice->denials['webpush']);

        $this->addPushDevice($reviewer);
        $this->assertSame(['database', 'webpush', 'email'], $this->evaluator->decide($reviewer, 'station_requests')->channels);

        $reviewer->pushSubscriptions()->update(['failure_count' => 50, 'backoff_until' => now()->addHour()]);
        $this->assertSame(['database', 'webpush', 'email'], $this->evaluator->decide($reviewer, 'station_requests')->channels, 'a device in back-off keeps the push channel: the alert is deferred, not dropped');

        $reviewer->pushSubscriptions()->delete();
        $this->assertSame(['database', 'email'], $this->evaluator->decide($reviewer, 'station_requests')->channels);
    }

    public function test_email_needs_a_city_email_on_the_member_record(): void
    {
        $reviewer = $this->stationReviewer(null);
        $this->row($reviewer, 'station_requests', true, false, true);

        $decision = $this->evaluator->decide($reviewer, 'station_requests');

        $this->assertSame(['database'], $decision->channels);
        $this->assertSame(NotificationDecision::NO_CITY_EMAIL, $decision->denials['email']);
    }

    public function test_the_publishers_channel_mask_can_only_remove_channels(): void
    {
        $member = $this->rosterMember();
        $this->addPushDevice($member);
        $this->row($member, 'department_updates', true, true, true);

        $inAppOnly = $this->evaluator->decide($member, 'department_updates', ['database']);
        $this->assertSame(['database'], $inAppOnly->channels);
        $this->assertSame(NotificationDecision::NOT_OFFERED, $inAppOnly->denials['webpush']);

        $pushOnly = $this->evaluator->decide($member, 'department_updates', ['webpush']);
        $this->assertSame(['webpush'], $pushOnly->channels);

        $nothing = $this->evaluator->decide($member, 'department_updates', []);
        $this->assertSame([], $nothing->channels);
    }

    public function test_unsupported_channels_stay_off_even_if_a_row_says_otherwise(): void
    {
        $member = $this->rosterMember('Firefighter', 'member@miamibeachfl.gov');
        $this->row($member, 'department_updates', true, true, true);

        $decision = $this->evaluator->decide($member, 'department_updates');

        $this->assertNotContains('email', $decision->channels);
        $this->assertSame(NotificationDecision::NOT_SUPPORTED, $decision->denials['email']);
    }

    public function test_a_locked_channel_cannot_be_turned_off(): void
    {
        $member = $this->member();
        $this->row($member, 'member_request_updates', false, false, false);

        $this->assertSame(['database'], $this->evaluator->decide($member, 'member_request_updates')->channels);
    }

    public function test_without_a_row_the_category_default_applies_to_eligible_members_only(): void
    {
        $reviewer = $this->stationReviewer();
        $outsider = $this->member();

        $this->assertSame(['database'], $this->evaluator->decide($reviewer, 'station_requests')->channels);
        $this->assertSame([], $this->evaluator->decide($outsider, 'station_requests')->channels);
        $this->assertSame([], $this->evaluator->decide($this->withPermissions($this->member(), 'admin.access', 'admin.support.view'), 'hub_support_tickets')->channels, 'opt-in categories send nothing until chosen');
    }

    public function test_recipients_are_exactly_the_eligible_active_members(): void
    {
        $eligible = $this->stationReviewer();
        $this->stationReviewerWith(['account_status' => 'disabled']);
        $this->member();
        $this->row($this->member(), 'station_requests', true, true, true);

        $this->assertSame([$eligible->id], $this->evaluator->recipients('station_requests')->pluck('id')->all());
    }

    private function stationReviewerWith(array $attributes): User
    {
        return $this->withPermissions($this->member($attributes), 'admin.access', 'admin.stations.view');
    }

    public function test_the_captain_5_category_follows_the_one_captain_5_access_decision(): void
    {
        $registry = app(NotificationCategoryRegistry::class);
        $captain5 = $registry->get('captain5_ems');

        $this->assertTrue($captain5->senderWired);
        $this->assertTrue($captain5->isLocked('database'), 'in-app Captain 5 alerts are always delivered');
        $this->assertNotNull($captain5->matchedRule(new EligibilityContext($this->withRole($this->member(), 'super_admin'))));
        $this->assertNull($captain5->matchedRule(new EligibilityContext($this->rosterMember('Firefighter'))));
    }

    public function test_no_sender_selects_recipients_from_saved_preferences(): void
    {
        $offenders = collect(File::allFiles(base_path('app')))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->filter(fn ($file): bool => preg_match('/whereHas\(\s*[\'"]notificationSubscriptions[\'"]/', File::get($file->getPathname())) === 1)
            ->map(fn ($file): string => str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1)))
            ->values()
            ->all();

        $this->assertSame([], $offenders, 'recipients must come from the evaluator, never from saved rows');
    }
}

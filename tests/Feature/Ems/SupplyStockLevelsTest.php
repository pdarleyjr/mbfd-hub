<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\StockAlertRecipient;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use App\Notifications\Ems\EmsLowStockNotification;
use App\Services\Ems\StockAlertRecipients;
use App\Services\Ems\StockAlertService;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Green, yellow and red stock levels and the low-stock alerts (docs/ems/captain5-medical-inventory-workflow.md section 12).
 * Fixture rows: sr.t.01 Gauze 4x4 (box of 200, A2), sr.t.02 Tape 1 inch (box of 24, A2), sr.t.03 Stethoscope (each, B1).
 */
final class SupplyStockLevelsTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $captain;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildEmsWorld();
        $this->captain = $this->emsCaptain();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $changes */
    private function save(array $changes, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->captain)
            ->patchJson('/ems/api/supply/master/items', ['changes' => $changes], ['Idempotency-Key' => 'k-'.Str::random(16)]);
    }

    /** @return array<string, mixed> */
    private function row(SupplyItem $item): array
    {
        return collect($this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data.items'))->firstWhere('id', $item->id);
    }

    private function onHand(SupplyItem $item, int $count): void
    {
        $this->save([['id' => $item->id, 'on_hand' => $count]])->assertOk();
    }

    public function test_presets_are_half_and_a_quarter_of_the_expected_amount(): void
    {
        $item = $this->supplyItem('sr.t.03');
        $this->save([['id' => $item->id, 'expected' => 20, 'on_hand' => 11]])->assertOk();

        $row = $this->row($item);
        $this->assertSame([10, 5, true, true], [$row['reorder_at'], $row['critical_at'], $row['reorder_at_auto'], $row['critical_at_auto']]);
        foreach ([11 => 'green', 20 => 'green', 10 => 'yellow', 6 => 'yellow', 5 => 'red', 1 => 'red', 0 => 'red'] as $count => $level) {
            $this->onHand($item, $count);
            $this->assertSame($level, $this->row($item)['level'], "{$count} of 20");
        }

        $this->save([['id' => $item->id, 'expected' => 4]])->assertOk();
        foreach ([4 => 'green', 3 => 'green', 2 => 'yellow', 1 => 'red'] as $count => $level) {
            $this->onHand($item, $count);
            $this->assertSame($level, $this->row($item)['level'], "{$count} of 4");
        }
    }

    public function test_boxes_are_compared_in_boxes_and_an_uncounted_or_unset_item_has_no_color(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $this->assertNull($this->row($tape)['level']);
        $this->save([['id' => $tape->id, 'count_unit' => 'box', 'units_per_package' => 24, 'expected' => 4, 'on_hand' => 2]])->assertOk();

        $row = $this->row($tape);
        $this->assertSame(['yellow', 2, 1], [$row['level'], $row['reorder_at'], $row['critical_at']]);

        $gauze = $this->supplyItem('sr.t.01');
        $this->save([['id' => $gauze->id, 'expected' => null, 'on_hand' => 3]])->assertOk();
        $this->assertNull($this->row($gauze)['level'], 'No expected amount, no color.');
    }

    public function test_a_person_can_move_either_line_and_blank_returns_it_to_the_preset(): void
    {
        $item = $this->supplyItem('sr.t.03');
        $this->save([['id' => $item->id, 'expected' => 20, 'on_hand' => 12, 'reorder_at' => 12, 'critical_at' => 8]])->assertOk();

        $row = $this->row($item);
        $this->assertSame([12, 8, false, false, 'yellow'], [$row['reorder_at'], $row['critical_at'], $row['reorder_at_auto'], $row['critical_at_auto'], $row['level']]);

        $this->save([['id' => $item->id, 'reorder_at' => null, 'critical_at' => null]])->assertOk();
        $row = $this->row($item);
        $this->assertSame([10, 5, true, true, 'green'], [$row['reorder_at'], $row['critical_at'], $row['reorder_at_auto'], $row['critical_at_auto'], $row['level']]);
        $this->assertNull(SupplyItem::query()->find($item->id)->reorder_at_qty_base);
    }

    public function test_lines_out_of_order_are_refused_on_the_field_to_fix(): void
    {
        $item = $this->supplyItem('sr.t.03');
        $this->save([['id' => $item->id, 'expected' => 10]])->assertOk();

        $this->save([['id' => $item->id, 'reorder_at' => 11]])->assertStatus(422)
            ->assertJsonPath('error.fields', fn ($fields): bool => array_key_exists('changes.0.reorder_at', (array) $fields));
        $this->save([['id' => $item->id, 'reorder_at' => 4, 'critical_at' => 5]])->assertStatus(422)
            ->assertJsonPath('error.fields', fn ($fields): bool => array_key_exists('changes.0.critical_at', (array) $fields));
        $this->save([['id' => $this->supplyItem('sr.t.01')->id, 'expected' => null, 'reorder_at' => 2]])->assertStatus(422);
        $this->assertNull(SupplyItem::query()->find($item->id)->reorder_at_qty_base, 'Nothing was saved.');
    }

    public function test_the_summary_counts_each_color(): void
    {
        $this->save([
            ['id' => $this->supplyItem('sr.t.03')->id, 'expected' => 20, 'on_hand' => 2],
            ['id' => $this->supplyItem('sr.t.01')->id, 'expected' => 4, 'on_hand' => 4],
        ])->assertOk();

        $summary = $this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data.summary');
        $this->assertSame([1, 0, 1], [$summary['red'], $summary['yellow'], $summary['green']]);
    }

    public function test_the_alert_list_hears_once_when_an_item_drops_into_red_and_again_after_it_recovers(): void
    {
        app(StockAlertRecipients::class)->add([$this->captain->id], null, 'Test');
        $item = $this->supplyItem('sr.t.03');
        $this->save([['id' => $item->id, 'expected' => 20, 'on_hand' => 15]])->assertOk();
        Notification::assertNothingSent();

        $this->onHand($item, 3);
        Notification::assertSentToTimes($this->captain, EmsLowStockNotification::class, 1);
        Notification::assertSentTo($this->captain, EmsLowStockNotification::class, function (EmsLowStockNotification $alert): bool {
            $this->assertSame('EMS supply low: Stethoscope', $alert->title());
            $this->assertSame('Stethoscope: 3 of 20 units on hand. Order 17 units.', $alert->body());
            $mail = $alert->toBudgetedEmail($this->captain);
            $this->assertStringContainsString('Order 17 units', $mail['html']);
            $this->assertSame('/daily/supply-room', $alert->toDatabase($this->captain)['actions'][0]['url']);
            $this->assertStringContainsString('Order 17 units', $alert->toWebPush($this->captain, $alert)->toArray()['body']);

            return true;
        });

        $this->onHand($item, 2);
        Notification::assertSentToTimes($this->captain, EmsLowStockNotification::class, 1);

        $this->onHand($item, 20);
        $this->onHand($item, 1);
        Notification::assertSentToTimes($this->captain, EmsLowStockNotification::class, 2);
    }

    public function test_several_items_dropping_in_one_save_make_one_message_and_an_open_order_is_named(): void
    {
        app(StockAlertRecipients::class)->add([$this->captain->id], null, 'Test');
        $stethoscope = $this->supplyItem('sr.t.03');
        $gauze = $this->supplyItem('sr.t.01');
        $this->save([['id' => $stethoscope->id, 'expected' => 4, 'on_hand' => 4], ['id' => $gauze->id, 'expected' => 4, 'on_hand' => 4]])->assertOk();
        $this->actingAs($this->captain)->postJson("/ems/api/supply/master/items/{$gauze->id}/order", ['status' => 'ordered', 'quantity' => 3], ['Idempotency-Key' => 'k-'.Str::random(16)])->assertCreated();

        $this->save([['id' => $stethoscope->id, 'on_hand' => 0], ['id' => $gauze->id, 'on_hand' => 1]])->assertOk();

        Notification::assertSentToTimes($this->captain, EmsLowStockNotification::class, 1);
        Notification::assertSentTo($this->captain, EmsLowStockNotification::class, function (EmsLowStockNotification $alert): bool {
            $this->assertSame('EMS supplies low: 2 items', $alert->title());
            $this->assertStringContainsString('Stethoscope: 0 of 4 units on hand. Order 4 units.', $alert->body());
            $this->assertStringContainsString('Gauze 4x4: 1 of 4 units on hand. Already ordered (3 units).', $alert->body());

            return true;
        });
    }

    public function test_only_people_on_the_list_who_can_read_the_supply_room_are_told(): void
    {
        $admin = $this->emsAdmin(['admin.ems.manage'], 'Off List Admin');
        $member = $this->emsMember('Firefighter', 'Listed Member');
        app(StockAlertRecipients::class)->add([$this->captain->id, $member->id], null, 'Test');
        $item = $this->supplyItem('sr.t.03');
        $this->save([['id' => $item->id, 'expected' => 20, 'on_hand' => 15]])->assertOk();

        $this->onHand($item, 1);

        Notification::assertSentTo($this->captain, EmsLowStockNotification::class);
        Notification::assertNotSentTo($admin, EmsLowStockNotification::class);
        Notification::assertNotSentTo($member, EmsLowStockNotification::class, 'The list grants nothing.');
        $registry = app(NotificationCategoryRegistry::class);
        $this->assertTrue($registry->forUser($this->captain->fresh())->has(NotificationCategoryRegistry::EMS_LOW_STOCK));
        $this->assertFalse($registry->forUser($member->fresh())->has(NotificationCategoryRegistry::EMS_LOW_STOCK));
    }

    public function test_it_offers_in_app_push_and_email_all_on_by_default(): void
    {
        $category = app(NotificationCategoryRegistry::class)->get(NotificationCategoryRegistry::EMS_LOW_STOCK);

        $this->assertSame([NotificationCategory::CHANNEL_DATABASE, NotificationCategory::CHANNEL_WEBPUSH, NotificationCategory::CHANNEL_EMAIL], $category->channels);
        foreach ($category->channels as $channel) {
            $this->assertTrue($category->defaults[$channel], $channel);
        }
    }

    public function test_turning_alerts_on_records_the_current_levels_without_sending_them(): void
    {
        $item = $this->supplyItem('sr.t.03');
        $this->save([['id' => $item->id, 'expected' => 20, 'on_hand' => 1]])->assertOk();
        app(StockAlertRecipients::class)->add([$this->captain->id], null, 'Test');

        $this->assertSame(0, app(StockAlertService::class)->evaluate());
        Notification::assertNothingSent();
        $this->assertSame('red', SupplyItem::query()->find($item->id)->stock_alert_level);
    }

    public function test_the_list_command_adds_and_removes_people_and_shows_who_will_not_be_alerted(): void
    {
        $member = $this->emsMember('Firefighter', 'Listed Member');

        $this->artisan('ems:stock-alert-recipients', ['action' => 'add', 'users' => [$this->captain->id, $member->id], '--by' => 'Chief request'])
            ->expectsOutputToContain('NO, will not be alerted')->assertSuccessful();
        $this->assertSame('Chief request', StockAlertRecipient::query()->where('user_id', $member->id)->value('added_by_name'));
        $this->artisan('ems:stock-alert-recipients', ['action' => 'remove', 'users' => [$member->id], '--by' => 'Chief request'])->assertSuccessful();
        $this->assertSame([$this->captain->id], StockAlertRecipient::query()->pluck('user_id')->all());
        $this->artisan('ems:stock-alert-recipients', ['action' => 'add', 'users' => [999999], '--by' => 'x'])->assertFailed();
        $this->artisan('ems:stock-alert-recipients', ['action' => 'add', 'users' => [$member->id]])->assertFailed();
        $this->artisan('ems:stock-alerts')->assertSuccessful();
    }
}

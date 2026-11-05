<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Filament\Admin\Pages\MedicalSupplyRoom;
use App\Jobs\Ems\SendKioskWithdrawalCopies;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Ems\KioskDevice;
use App\Models\Ems\KioskSearchTerm;
use App\Models\Ems\KioskWithdrawal;
use App\Models\Ems\StockAlertRecipient;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Ems\KioskScreenOfflineNotification;
use App\Notifications\Ems\KioskWithdrawalDailySummary;
use App\Notifications\Ems\KioskWithdrawalNotice;
use App\Notifications\Ems\KioskWithdrawalReceipt;
use App\Services\Ems\EmsException;
use App\Services\Ems\Kiosk\KioskDevices;
use App\Services\Ems\Kiosk\KioskNotifier;
use App\Services\Ems\Kiosk\KioskSearchLearning;
use App\Services\Ems\SupplyData\DistributionQuery;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Notifications\NotificationCategoryRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The supply room screen (docs/ems/supply-room-kiosk.md): paired once, no member login, withdrawals straight off
 * the master inventory. Fixture rows: sr.t.01 Gauze 4x4, sr.t.02 Tape 1 inch, sr.t.03 Stethoscope (each).
 */
final class SupplyKioskTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $captain;

    private User $member;

    private string $cookie = '';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withoutVite();
        $this->buildEmsWorld();
        $this->captain = $this->emsCaptain();
        $this->member = $this->emsMember('Firefighter', 'Jane Doe');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function pairScreen(): KioskDevice
    {
        $pairing = app(KioskDevices::class)->createPairing($this->captain, 'Supply room screen');
        $token = Str::afterLast($pairing['url'], '/');
        $this->get('/supply-kiosk/pair/'.$token)->assertOk()->assertSee('Pair this screen');
        $response = $this->post('/supply-kiosk/pair/'.$token)->assertRedirect('/supply-kiosk');
        $this->cookie = (string) $response->getCookie(KioskDevices::COOKIE)?->getValue();
        $this->assertNotSame('', $this->cookie);

        return $pairing['device']->fresh();
    }

    /** @param array<string, mixed> $body */
    private function kiosk(string $method, string $uri, array $body = []): TestResponse
    {
        app()->forgetScopedInstances();

        return $this->withCredentials()->withCookie(KioskDevices::COOKIE, $this->cookie)->json($method, $uri, $body);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function take(array $lines, array $extra = []): TestResponse
    {
        return $this->kiosk('POST', '/supply-kiosk/api/withdrawals', $extra + [
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $this->member->employee_id,
            'unit' => null,
            'lines' => $lines,
        ]);
    }

    /** @param array<string, mixed> $more */
    private function shelfCount(SupplyItem $item, int $onHand, array $more = []): void
    {
        $this->actingAs($this->captain)->patchJson('/ems/api/supply/master/items', ['changes' => [['id' => $item->id, 'on_hand' => $onHand] + $more]], ['Idempotency-Key' => 'k-'.Str::random(16)])->assertOk();
        $this->signOut();
    }

    /** @return array<string, mixed> */
    private function row(SupplyItem $item): array
    {
        $row = collect($this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data.items'))->firstWhere('id', $item->id);
        $this->signOut();

        return $row;
    }

    /** The screen never has a signed-in session; drop the captain's before the next screen call. */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
    }

    private function rescue(string $designation = 'R 22'): Apparatus
    {
        $slug = Str::slug($designation).'-t';

        return Apparatus::query()->create(['unit_id' => strtoupper($slug), 'name' => $designation, 'type' => 'Rescue', 'designation' => $designation, 'slug' => $slug, 'status' => 'In Service']);
    }

    public function test_an_unpaired_browser_can_open_the_page_but_reach_nothing(): void
    {
        $this->get('/supply-kiosk')->assertOk()->assertSee('supply-kiosk-root', false);
        $this->getJson('/supply-kiosk/api/bootstrap')->assertStatus(401)->assertJsonPath('error.code', 'kiosk_not_paired');
        $this->postJson('/supply-kiosk/api/withdrawals', [])->assertStatus(401)->assertJsonPath('error.code', 'kiosk_not_paired');
        $this->withCredentials()->withCookie(KioskDevices::COOKIE, '1.'.str_repeat('a', 64))->getJson('/supply-kiosk/api/bootstrap')->assertStatus(401);
    }

    public function test_a_pairing_link_works_once_and_only_people_who_edit_the_inventory_can_make_one(): void
    {
        $pairing = app(KioskDevices::class)->createPairing($this->captain, 'Supply room screen');
        $token = Str::afterLast($pairing['url'], '/');
        $this->post('/supply-kiosk/pair/'.$token)->assertRedirect('/supply-kiosk');
        $this->post('/supply-kiosk/pair/'.$token)->assertStatus(410);
        $this->get('/supply-kiosk/pair/'.$token)->assertStatus(410);

        $this->expectException(EmsException::class);
        app(KioskDevices::class)->createPairing($this->member, 'Nope');
    }

    public function test_bootstrap_lists_items_members_units_and_shorthand_without_personal_details(): void
    {
        $this->pairScreen();
        // E1 is already on its truck in the EMS world; a second truck cannot be named E 1.
        $this->rescue('E 7');
        $this->rescue('R 22');
        $this->rescue('Reserve');
        SupplyItem::query()->where('source_row_key', 'sr.t.02')->update(['controlled_substance' => true]);

        $data = $this->kiosk('GET', '/supply-kiosk/api/bootstrap')->assertOk()->json('data');

        $labels = array_column($data['units'], 'label');
        $this->assertContains('R 22', $labels);
        $this->assertNotContains('Reserve', $labels, 'Reserve placeholders are typed in, not listed.');
        $firstEngine = collect($labels)->search(fn (string $l): bool => str_starts_with($l, 'E'));
        $lastRescue = collect($labels)->filter(fn (string $l): bool => str_starts_with($l, 'R'))->keys()->last();
        $this->assertLessThan($firstEngine, $lastRescue, 'Rescues come first.');
        $this->assertLessThan(array_search('R 22', $labels, true), array_search('R2', $labels, true), 'Numbers sort as numbers.');
        $member = collect($data['members'])->firstWhere('employee_id', $this->member->employee_id);
        $this->assertSame(['employee_id', 'name'], array_keys($member));
        $this->assertSame('Firefighter Jane Doe', $member['name']);
        $this->assertNotContains('Tape 1 inch', array_column($data['items'], 'name'), 'Controlled items never show.');
        $stethoscope = collect($data['items'])->firstWhere('name', 'Stethoscope');
        $this->assertSame([['key' => 'each', 'label' => 'each', 'plural' => 'each', 'per' => 1]], $stethoscope['units']);
        $this->assertArrayHasKey('nrb', $data['shorthand']);
        $this->assertNotEmpty($data['csrf_token']);
        $this->assertNotNull(KioskDevice::query()->first()->last_seen_at);
    }

    public function test_a_withdrawal_comes_straight_off_the_master_inventory_and_both_copies_go_out(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 10, ['expected' => 10]);
        StockAlertRecipient::query()->create(['user_id' => $this->captain->id, 'added_by_name' => 'test', 'created_at' => now()]);
        $rescue = $this->rescue();

        $response = $this->take([['supply_item_id' => $item->id, 'quantity' => 3, 'unit' => 'each']], ['unit' => ['apparatus_id' => $rescue->id, 'label' => null]])
            ->assertCreated()->assertJsonPath('data.employee_name', 'Firefighter Jane Doe')
            ->assertJsonPath('data.unit_label', 'R 22')->assertJsonPath('data.copy', 'sent')
            ->assertJsonPath('data.lines.0.quantity', 3);
        $this->assertMatchesRegularExpression('/^W-\d{6}$/', (string) $response->json('data.number'));

        $this->assertSame(7, $this->row($item)['on_hand']);
        $movement = StockMovement::query()->where('supply_item_id', $item->id)->latest('id')->first();
        $withdrawal = KioskWithdrawal::query()->sole();
        $this->assertSame([StockMovement::ISSUED, -3, 'kiosk:w'.$withdrawal->id.':l'.$withdrawal->lines()->sole()->id], [$movement->movement_type, $movement->quantity_base, $movement->idempotency_key]);
        $this->assertSame($withdrawal->id, (int) $movement->kiosk_withdrawal_id);
        $this->assertSame('Firefighter Jane Doe', $movement->actor_name);

        Notification::assertSentTo($this->member, KioskWithdrawalReceipt::class);
        Notification::assertSentTo($this->captain, KioskWithdrawalNotice::class);
    }

    public function test_an_unknown_or_departed_employee_id_is_refused_and_nothing_is_taken(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);

        $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']], ['employee_id' => '99999'])
            ->assertStatus(422)->assertJsonPath('error.code', 'employee_not_found');
        Employee::query()->whereKey($this->member->employee_profile_id)->update(['roster_status' => 'departed']);
        $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']])->assertStatus(422)->assertJsonPath('error.code', 'employee_not_found');

        $this->assertSame(5, $this->row($item)['on_hand']);
        $this->assertSame(0, KioskWithdrawal::query()->count());
    }

    public function test_the_same_withdrawal_sent_twice_is_taken_once(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);
        $body = ['client_uuid' => (string) Str::uuid(), 'employee_id' => $this->member->employee_id, 'lines' => [['supply_item_id' => $item->id, 'quantity' => 2, 'unit' => 'each']]];

        $first = $this->kiosk('POST', '/supply-kiosk/api/withdrawals', $body)->assertCreated();
        $this->kiosk('POST', '/supply-kiosk/api/withdrawals', $body)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.number', $first->json('data.number'));
        $this->kiosk('POST', '/supply-kiosk/api/withdrawals', ['lines' => [['supply_item_id' => $item->id, 'quantity' => 3, 'unit' => 'each']]] + $body)
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_mismatch');

        $this->assertSame(3, $this->row($item)['on_hand']);
    }

    public function test_an_item_the_ledger_shows_empty_is_still_taken_and_flagged_for_a_count(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 1, ['expected' => 10]);

        $this->take([['supply_item_id' => $item->id, 'quantity' => 3, 'unit' => 'each']])->assertCreated();

        $this->assertTrue(KioskWithdrawal::query()->sole()->lines()->sole()->shelf_showed_empty);
        $row = $this->row($item);
        $this->assertSame([0, 'red', true], [$row['on_hand'], $row['level'], $row['count_due']]);
    }

    public function test_boxes_convert_to_units_and_a_note_alone_takes_nothing(): void
    {
        $this->pairScreen();
        $tape = $this->supplyItem('sr.t.02');
        $this->shelfCount($tape, 4, ['count_unit' => 'box', 'units_per_package' => 24]);

        $bootstrapTape = collect($this->kiosk('GET', '/supply-kiosk/api/bootstrap')->json('data.items'))->firstWhere('id', $tape->id);
        $this->assertSame(['each', 'box'], array_column($bootstrapTape['units'], 'key'));
        $this->assertSame('box', $bootstrapTape['default_unit']);

        $this->take([['supply_item_id' => $tape->id, 'quantity' => 1, 'unit' => 'box'], ['supply_item_id' => $tape->id, 'quantity' => 6, 'unit' => 'each']])->assertCreated();
        $this->assertSame(-30, (int) StockMovement::query()->where('supply_item_id', $tape->id)->where('movement_type', StockMovement::ISSUED)->sum('quantity_base'));
        $this->take([['supply_item_id' => $tape->id, 'quantity' => 1, 'unit' => 'case']])->assertStatus(422);

        $movements = StockMovement::query()->count();
        $this->take([], ['note' => 'Needed 20g IV catheters, none on the shelf'])->assertCreated()->assertJsonPath('data.lines', []);
        $this->take([])->assertStatus(422);
        $this->assertSame($movements, StockMovement::query()->count());
    }

    public function test_undo_puts_everything_back_within_a_minute_from_the_same_screen_only(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 10);

        $uuid = $this->take([['supply_item_id' => $item->id, 'quantity' => 4, 'unit' => 'each']])->assertCreated()->json('data.uuid');
        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$uuid}/undo")->assertOk()->assertJsonPath('data.status', 'undone');
        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$uuid}/undo")->assertOk()->assertJsonPath('data.status', 'undone');
        $row = $this->row($item);
        $this->assertSame([10, 0], [$row['on_hand'], $row['deducted']], 'An undone withdrawal leaves no deduction behind.');

        $late = $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']])->json('data.uuid');
        $this->travel(61)->seconds();
        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$late}/undo")->assertStatus(409)->assertJsonPath('error.code', 'undo_window_closed');

        $other = $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']])->json('data.uuid');
        $this->pairScreen();
        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$other}/undo")->assertStatus(404);
    }

    public function test_a_revoked_screen_stops_working_at_once(): void
    {
        $device = $this->pairScreen();
        $this->kiosk('GET', '/supply-kiosk/api/bootstrap')->assertOk();

        app(KioskDevices::class)->revoke($device->id, $this->captain);

        $this->kiosk('GET', '/supply-kiosk/api/bootstrap')->assertStatus(401)->assertJsonPath('error.code', 'kiosk_not_paired');
    }

    public function test_a_member_without_a_hub_account_gets_the_copy_at_their_city_email(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);
        $employee = Employee::query()->create(['employee_id' => '54321', 'name' => 'Sam Roe', 'rank' => 'Lieutenant', 'city_email' => 'sam.roe@example.test', 'roster_status' => 'active', 'password' => 'unused', 'must_change_password' => false]);

        $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']], ['employee_id' => '54321'])->assertCreated()->assertJsonPath('data.copy', 'sent');

        Notification::assertSentTo($employee, KioskWithdrawalReceipt::class, fn (KioskWithdrawalReceipt $n, array $channels): bool => $channels === [BudgetedMailChannel::class]);
    }

    public function test_search_words_learn_only_after_three_picks_and_a_person_approves(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 20);
        foreach (range(1, 3) as $i) {
            $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']], ['searches' => [['term' => '  Steth Scope ', 'supply_item_id' => $item->id]]])->assertCreated();
            if ($i < 3) {
                $this->assertSame([], app(KioskSearchLearning::class)->suggestions());
            }
        }

        $suggestion = app(KioskSearchLearning::class)->suggestions()[0];
        $this->assertSame(['steth scope', 'Stethoscope', 3], [$suggestion['term'], $suggestion['item_name'], $suggestion['picks']]);
        $this->assertSame([], collect($this->kiosk('GET', '/supply-kiosk/api/bootstrap')->json('data.items'))->firstWhere('id', $item->id)['learned_aliases']);

        app(KioskSearchLearning::class)->decide([$suggestion['id']], [], $this->captain);
        $this->assertSame(['steth scope'], collect($this->kiosk('GET', '/supply-kiosk/api/bootstrap')->json('data.items'))->firstWhere('id', $item->id)['learned_aliases']);
        $this->assertSame(KioskSearchTerm::APPROVED, KioskSearchTerm::query()->sole()->status);
    }

    public function test_the_daily_summary_and_the_offline_watch_reach_the_alert_list(): void
    {
        $device = $this->pairScreen();
        StockAlertRecipient::query()->create(['user_id' => $this->captain->id, 'added_by_name' => 'test', 'created_at' => now()]);
        Employee::query()->whereKey($this->captain->employee_profile_id)->update(['city_email' => 'captain5@example.test']);
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);

        $this->artisan('ems:kiosk-daily-summary')->assertSuccessful();
        Notification::assertNotSentTo($this->captain, KioskWithdrawalDailySummary::class);

        $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']])->assertCreated();
        $this->artisan('ems:kiosk-daily-summary')->assertSuccessful();
        Notification::assertSentTo($this->captain, KioskWithdrawalDailySummary::class, fn (KioskWithdrawalDailySummary $n): bool => count($n->rows) === 1);

        $this->artisan('ems:kiosk-watch')->assertSuccessful();
        Notification::assertNotSentTo($this->captain, KioskScreenOfflineNotification::class);
        KioskDevice::query()->whereKey($device->id)->update(['last_seen_at' => now()->subMinutes(20)]);
        $this->artisan('ems:kiosk-watch')->assertSuccessful();
        $this->artisan('ems:kiosk-watch')->assertSuccessful();
        Notification::assertSentToTimes($this->captain, KioskScreenOfflineNotification::class, 1);

        $this->kiosk('POST', '/supply-kiosk/api/heartbeat')->assertOk();
        $this->assertNull(KioskDevice::query()->find($device->id)->offline_alerted_at);
    }

    public function test_captain_5_sees_the_screen_and_its_withdrawals_and_a_member_does_not(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);
        $this->take([['supply_item_id' => $item->id, 'quantity' => 2, 'unit' => 'each']])->assertCreated();

        $this->actingAs($this->captain)->getJson('/ems/api/supply/screen')->assertOk()
            ->assertJsonPath('data.withdrawals.0.employee_name', 'Firefighter Jane Doe')
            ->assertJsonPath('data.withdrawals.0.lines.0.quantity', 2)
            ->assertJsonPath('data.screens.0.state', 'online');
        $this->signOut();
        $this->actingAs($this->member)->getJson('/ems/api/supply/screen')->assertForbidden();
    }

    public function test_the_admin_tab_pairs_and_revokes_a_screen_and_exports_withdrawals(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
        $this->forgetEmsMemo();
        $this->actingAs($admin->fresh());

        Livewire::test(MedicalSupplyRoom::class)->set('tab', 'screen')->assertSee('No screen is paired yet')
            ->callAction('pairScreen', ['name' => 'Supply room screen'])->assertHasNoActionErrors()->assertNotified();
        $device = KioskDevice::query()->sole();

        Livewire::test(MedicalSupplyRoom::class)->set('tab', 'screen')->assertSee('Waiting to be paired')
            ->callAction('revokeScreen', arguments: ['screen' => $device->id])->assertHasNoActionErrors();
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_a_screen_pairs_with_a_short_code_typed_on_it_once(): void
    {
        $pairing = app(KioskDevices::class)->createPairing($this->captain, 'Supply room screen');
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{3}-[A-HJKMNP-Z2-9]{3}$/', $pairing['code']);

        $this->postJson('/supply-kiosk/pair-code', ['code' => 'ZZZ-ZZZ'])->assertStatus(410)->assertJsonPath('error.code', 'pairing_link_ended');
        $response = $this->postJson('/supply-kiosk/pair-code', ['code' => strtolower(str_replace('-', ' ', $pairing['code']))])
            ->assertOk()->assertJsonPath('data.device.name', 'Supply room screen');
        $this->cookie = (string) $response->getCookie(KioskDevices::COOKIE)?->getValue();
        $this->kiosk('GET', '/supply-kiosk/api/bootstrap')->assertOk();

        $this->postJson('/supply-kiosk/pair-code', ['code' => $pairing['code']])->assertStatus(410);
        $this->post('/supply-kiosk/pair/'.Str::afterLast($pairing['url'], '/'))->assertStatus(410);
    }

    public function test_copies_wait_out_the_undo_minute_and_an_undone_withdrawal_sends_none(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);
        StockAlertRecipient::query()->create(['user_id' => $this->captain->id, 'added_by_name' => 'test', 'created_at' => now()]);
        Queue::fake([SendKioskWithdrawalCopies::class]);

        $uuid = $this->take([['supply_item_id' => $item->id, 'quantity' => 1, 'unit' => 'each']])->assertCreated()->json('data.uuid');
        Queue::assertPushed(SendKioskWithdrawalCopies::class, fn (SendKioskWithdrawalCopies $job): bool => $job->delay !== null);
        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$uuid}/undo")->assertOk();

        app(KioskNotifier::class)->taken(KioskWithdrawal::query()->sole()->id);
        Notification::assertNothingSentTo($this->member);
        Notification::assertNothingSentTo($this->captain);
    }

    public function test_a_withdrawal_undone_before_it_arrived_is_refused_when_it_arrives_late(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 5);
        $uuid = (string) Str::uuid();

        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$uuid}/undo")->assertNotFound();
        $this->take([['supply_item_id' => $item->id, 'quantity' => 2, 'unit' => 'each']], ['client_uuid' => $uuid])
            ->assertStatus(409)->assertJsonPath('error.code', 'withdrawal_undone');

        $this->assertSame(5, $this->row($item)['on_hand']);
        $this->assertSame(0, KioskWithdrawal::query()->count());
    }

    public function test_supply_data_counts_screen_withdrawals_by_unit_and_not_as_unlinked(): void
    {
        $this->pairScreen();
        $item = $this->supplyItem('sr.t.03');
        $this->shelfCount($item, 10);
        $rescue = $this->rescue();
        $this->take([['supply_item_id' => $item->id, 'quantity' => 3, 'unit' => 'each']], ['unit' => ['apparatus_id' => $rescue->id, 'label' => null]])->assertCreated();
        $undone = $this->take([['supply_item_id' => $item->id, 'quantity' => 2, 'unit' => 'each']])->json('data.uuid');
        $this->kiosk('POST', "/supply-kiosk/api/withdrawals/{$undone}/undo")->assertOk();

        $query = app(DistributionQuery::class);
        $filters = SupplyDataFilters::fromArray(['period' => '30d']);
        [$from, $to] = [$filters->fromUtc(), $filters->toUtc()];
        $byItem = $query->byItem($from, $to, $filters)->firstWhere('item_id', $item->id);
        $this->assertSame([1, 3], [(int) $byItem->events, (int) $byItem->net_base], 'The undone withdrawal nets to zero.');
        $this->assertSame((int) $rescue->id, (int) $query->byUnit($from, $to, $filters)->sole()->apparatus_id);
        $this->assertSame(0, (int) $query->integrity($from, $to)->unattributed);
        $this->assertSame(3, (int) $query->byUnit($from, $to, SupplyDataFilters::fromArray(['period' => '30d', 'unit' => 'unit:'.$rescue->id]))->sole()->net_base);
    }

    public function test_too_many_wrong_pairing_codes_void_every_open_code(): void
    {
        $pairing = app(KioskDevices::class)->createPairing($this->captain, 'Supply room screen');
        foreach (range(1, 50) as $i) {
            try {
                app(KioskDevices::class)->pairWithCode('ZZZ'.str_pad((string) $i, 3, '2'));
            } catch (EmsException) {
            }
        }

        $this->postJson('/supply-kiosk/pair-code', ['code' => $pairing['code']])->assertStatus(410);
    }

    public function test_each_bootstrap_renews_the_screen_cookie(): void
    {
        $this->pairScreen();

        $cookie = $this->kiosk('GET', '/supply-kiosk/api/bootstrap')->assertOk()->getCookie(KioskDevices::COOKIE);
        $this->assertSame($this->cookie, $cookie?->getValue());
        $this->assertGreaterThan(now()->addYears(5)->timestamp, $cookie?->getExpiresTime());
        $this->assertSame('strict', $cookie?->getSameSite());
        $this->assertTrue($cookie?->isHttpOnly());
    }

    public function test_the_withdrawals_category_offers_the_bell_and_email(): void
    {
        $category = app(NotificationCategoryRegistry::class)->get(NotificationCategoryRegistry::EMS_SUPPLY_WITHDRAWALS);
        $this->assertNotNull($category);
        $this->assertSame(['database' => true, 'webpush' => false, 'email' => true], $category->defaults);
    }
}

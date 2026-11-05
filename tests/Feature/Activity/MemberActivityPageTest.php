<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Filament\Employee\Pages\MyActivity;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Services\Activity\MemberActivityFeed;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** The dashboard card, the "View all activity" page and the cost of building them. */
final class MemberActivityPageTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->me = $this->member('Olive Owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(User $by, string $number): StationRequest
    {
        $this->fixtureCounter++;
        $station = Station::query()->create(['station_number' => 600 + $this->fixtureCounter, 'name' => 'Station Y'.$this->fixtureCounter, 'address' => '1 Test Way', 'is_active' => true]);

        return StationRequest::query()->create([
            'station_id' => $station->id, 'actor_user_id' => $by->id, 'requested_by_employee_id' => $by->employee_profile_id,
            'requester_name_snapshot' => $by->name, 'request_type' => 'repair', 'title' => 'Item '.$number,
            'description' => 'Details', 'request_number' => $number, 'status' => 'pending',
        ]);
    }

    private function staffNote(StationRequest $request, string $text): void
    {
        $staff = $this->reviewer();
        $update = new StationRequestUpdate;
        $update->forceFill([
            'station_request_id' => $request->id, 'status' => 'pending', 'public_note' => $text,
            'changed_by_user_id' => $staff->id, 'created_at' => '2026-11-04 10:00:00', 'updated_at' => '2026-11-04 10:00:00',
        ])->save();
    }

    public function test_the_dashboard_shows_the_card_with_an_empty_state(): void
    {
        $html = $this->actingAs($this->me)->get('/employee/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('My activity', $html);
        $this->assertStringContainsString('View all activity', $html);
        $this->assertStringContainsString('Nothing yet. Inspections, requests and messages you send show up here.', $html);
        $this->assertSame(1, substr_count($html, 'id="my-activity-heading"'));
    }

    public function test_the_dashboard_card_lists_rows_with_the_marker_links_and_unread_dot(): void
    {
        $request = $this->request($this->me, 'SR-CARD-1');
        $this->staffNote($request, 'Plumber booked for Friday.');

        $html = $this->actingAs($this->me)->get('/employee/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('SR-CARD-1', $html);
        $this->assertStringContainsString('Plumber booked for Friday.', $html);
        $this->assertStringContainsString('/employee/activity/open/station_request', $html);
        $this->assertStringContainsString('data-unread', $html);
        $this->assertStringContainsString('New update', $html);
    }

    public function test_the_full_page_renders_filters_and_paginates(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $this->request($this->me, 'SR-PAGE-'.$i);
        }
        $this->request($this->me, 'SR-NEEDLE');
        $this->actingAs($this->me);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        $page = Livewire::test(MyActivity::class);
        $page->assertSee('28 items.');
        $this->assertCount(25, $page->viewData('items'));
        $this->assertSame(2, $page->viewData('pages'));

        $page->call('nextPage');
        $this->assertCount(3, $page->viewData('items'));

        $page->set('search', 'needle');
        $this->assertCount(1, $page->viewData('items'));
        $this->assertSame(1, $page->get('pageNumber'));
        $page->assertSee('SR-NEEDLE')->assertSee('1 item matches.');

        $page->set('search', 'zzz-nothing')->assertSee('Nothing matches that search');
        $page->call('clearFilters')->assertSee('28 items.');
    }

    public function test_the_full_page_url_is_served_to_a_member(): void
    {
        $this->actingAs($this->me)->get('/employee/my-activity')->assertOk()->assertSee('My activity');
    }

    public function test_query_count_does_not_grow_with_the_number_of_items(): void
    {
        $few = $this->queriesFor(3);
        $many = $this->queriesFor(20);

        $this->assertSame($few, $many, 'the card runs the same queries for 3 and for 20 items');
        $this->assertLessThanOrEqual(60, $many);
    }

    private function queriesFor(int $count): int
    {
        $user = $this->member('Budget '.$count);
        $inspection = $this->inspectionBy($this->rig(), $user, '2026-11-04 09:00:00');
        for ($i = 1; $i <= $count; $i++) {
            $request = $this->request($user, 'SR-BUDGET-'.$count.'-'.$i);
            $this->staffNote($request, 'Note '.$i);
            $this->openDefect($inspection->apparatus, $inspection, $user, 'Budget Item '.$count.'-'.$i);
        }
        DB::table('apparatus_inspections')->where('id', $inspection->id)->update(['review_status' => 'pending_review']);

        $feed = app(MemberActivityFeed::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $items = $feed->forCard($user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotEmpty($items);

        return $queries;
    }
}

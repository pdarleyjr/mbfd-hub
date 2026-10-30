<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Enums\AccountStatus;
use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Models\User;
use App\Services\Narcotics\NarcoticsImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

final class NarcoticsCheckMemberSubmissionTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
    }

    public function test_a_member_sends_a_check_and_it_is_durably_stored_before_the_server_says_so(): void
    {
        $id = (string) Str::uuid();

        $response = $this->sendCheck($this->member, $this->upload($this->jpegBytes(1200, 900)), ['client_submission_id' => $id, 'note' => '  Box 2 sealed, count matches.  '])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Sent to Captain 5')
            ->assertJsonPath('data.member_note', 'Box 2 sealed, count matches.')
            ->assertJsonPath('data.archived', false)
            ->assertJsonPath('data.image.available', true);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $check = NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame($this->member->id, $check->submitted_by_user_id);
        $this->assertSame('Pat Member', $check->submitter_name);
        $this->assertSame('Firefighter', $check->submitter_rank);
        $this->assertSame($id, $check->client_submission_id);
        $this->assertSame(NarcoticsCheck::PENDING, $check->status);
        $this->assertNull($check->decided_at);
        $this->assertNull($check->archived_at);

        // Three sizes exist on the private disk and nowhere else.
        $disk = Storage::disk(NarcoticsImageStore::disk());
        foreach ([$check->image_path, $check->preview_path, $check->thumb_path] as $path) {
            $this->assertTrue($disk->exists($path), $path);
            $this->assertStringStartsWith('narcotics-checks/', $path);
        }
        $this->assertCount(3, $this->storedFiles());
        $this->assertSame(hash('sha256', $disk->get($check->image_path)), $check->image_sha256);
        $this->assertEmpty(Storage::disk('public')->allFiles('narcotics-checks'));

        // Nothing a browser receives names a file, a disk or a hash.
        $body = $response->getContent();
        $this->assertStringNotContainsString($check->image_path, $body);
        $this->assertStringNotContainsString($check->image_sha256, $body);
        $this->assertStringNotContainsString('narcotics-checks/', $body);
        $this->assertSame("/api/narcotics-checks/{$check->uuid}/image/thumb", $response->json('data.image.thumb_url'));

        // One audit row: who sent it, with a snapshot of what was stored.
        $event = NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->sole();
        $this->assertSame('submitted', $event->action);
        $this->assertSame('member', $event->actor_context);
        $this->assertSame($this->member->id, $event->actor_user_id);
        $this->assertSame($check->image_sha256, $event->payload['image']['sha256']);
    }

    public function test_the_note_is_optional(): void
    {
        $check = $this->submitOk($this->member);

        $this->assertNull($check->member_note);
    }

    public function test_the_same_submission_sent_twice_is_one_check_and_one_set_of_files(): void
    {
        $id = (string) Str::uuid();
        $first = $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['client_submission_id' => $id])->assertStatus(201);
        $second = $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['client_submission_id' => $id])
            ->assertOk()
            ->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, NarcoticsCheck::query()->count());
        $this->assertSame(1, NarcoticsCheckEvent::query()->count());
        $this->assertCount(3, $this->storedFiles(), 'a retry must not leave a second set of photos behind');
    }

    public function test_someone_elses_submission_id_is_refused_without_confirming_it_exists(): void
    {
        $id = (string) Str::uuid();
        $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['client_submission_id' => $id])->assertStatus(201);

        $this->sendCheck($this->otherMember, $this->upload($this->jpegBytes()), ['client_submission_id' => $id])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
        $this->assertSame(1, NarcoticsCheck::query()->count());
    }

    public function test_a_failed_attempt_leaves_no_row_and_no_files_and_the_same_id_can_be_retried(): void
    {
        $id = (string) Str::uuid();

        $this->sendCheck($this->member, $this->upload('not a photo at all'), ['client_submission_id' => $id])->assertStatus(422);
        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());

        $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['client_submission_id' => $id])->assertStatus(201);
        $this->assertSame(1, NarcoticsCheck::query()->count());
    }

    public function test_a_missing_photo_and_a_missing_submission_id_are_refused_in_plain_words(): void
    {
        $this->sendCheck($this->member, null, [], withPhoto: false)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'photo_missing')
            ->assertJsonPath('error.message', 'Choose or take a photo first.');

        $this->actingAs($this->member);
        $this->post('/api/narcotics-checks', ['photo' => $this->upload($this->jpegBytes())], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, NarcoticsCheck::query()->count());
    }

    public function test_wrong_type_oversize_and_polyglot_files_are_refused_and_nothing_is_stored(): void
    {
        $gif = imagecreatetruecolor(700, 500);
        ob_start();
        imagegif($gif);
        $gifBytes = (string) ob_get_clean();

        $this->sendCheck($this->member, $this->upload($gifBytes, 'photo.jpg', 'image/jpeg'))->assertStatus(422)->assertJsonPath('error.code', 'photo_type');
        $this->sendCheck($this->member, $this->upload("%PDF-1.7\n", 'scan.pdf', 'application/pdf'))->assertStatus(422)->assertJsonPath('error.code', 'photo_type');
        $this->sendCheck($this->member, $this->upload($this->jpegBytes()."\n<?php echo 'x'; ?>", 'shell.php.jpg', 'image/jpeg'))->assertStatus(422)->assertJsonPath('error.code', 'photo_unsafe');

        config()->set('narcotics_checks.image.max_upload_kb', 8);
        $this->sendCheck($this->member, $this->upload($this->jpegBytes(1600, 1200)))->assertStatus(422)->assertJsonPath('error.code', 'photo_too_large');

        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_note_length_is_capped(): void
    {
        $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['note' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'The note can be at most 500 characters.');
        $this->assertSame(0, NarcoticsCheck::query()->count());
    }

    public function test_a_member_cannot_stack_up_unlimited_checks_for_captain_5(): void
    {
        config()->set('narcotics_checks.max_pending_per_member', 2);
        $this->submitOk($this->member);
        $this->submitOk($this->member);

        $this->sendCheck($this->member, $this->upload($this->jpegBytes()))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_pending');
        $this->assertSame(2, NarcoticsCheck::query()->count());

        // Another member is unaffected.
        $this->submitOk($this->otherMember);
    }

    public function test_guests_and_accounts_without_a_personnel_profile_cannot_send_one(): void
    {
        $this->signOut();
        $this->post('/api/narcotics-checks', [], ['Accept' => 'application/json'])->assertStatus(401);

        $noProfile = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        $this->sendCheck($noProfile)->assertStatus(403);
        $this->assertSame(0, NarcoticsCheck::query()->count());
    }

    public function test_the_kill_switch_closes_every_member_endpoint_but_keeps_the_records(): void
    {
        $check = $this->submitOk($this->member);
        config()->set('narcotics_checks.enabled', false);

        $this->sendCheck($this->member)->assertStatus(403);
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/form')->assertStatus(403);
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->assertStatus(403);
        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertStatus(403);
        $this->actingAs($this->member)->get("/api/narcotics-checks/{$check->uuid}/image/thumb")->assertNotFound();
        $this->assertSame(1, NarcoticsCheck::query()->count());
    }

    public function test_the_form_endpoint_tells_the_browser_the_limits(): void
    {
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/form')
            ->assertOk()
            ->assertJsonPath('data.note_max_length', 500)
            ->assertJsonPath('data.max_edge', 3200)
            ->assertJsonPath('data.max_upload_bytes', 15360 * 1024)
            ->assertJsonFragment(['image/jpeg']);
    }

    public function test_a_member_sees_only_their_own_checks_newest_first(): void
    {
        $first = $this->submitOk($this->member);
        $second = $this->submitOk($this->member);
        $theirs = $this->submitOk($this->otherMember);

        $list = $this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->assertOk();
        $this->assertSame([$second->uuid, $first->uuid], array_column($list->json('data'), 'id'));
        $this->assertSame(2, $list->json('meta.total'));

        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$first->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->uuid)
            ->assertJsonPath('data.history.0.action', 'submitted');

        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$theirs->uuid}")->assertNotFound();
        $this->actingAs($this->member)->getJson('/api/narcotics-checks/mine/'.Str::uuid())->assertNotFound();
    }

    public function test_every_narcotics_route_lives_under_api_so_the_service_worker_never_caches_a_photo(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains((string) $route->getName(), 'narcotics.api.'))
            ->map(fn ($route): string => $route->uri());

        $this->assertGreaterThanOrEqual(8, $uris->count());
        foreach ($uris as $uri) {
            $this->assertStringStartsWith('api/narcotics-checks', $uri);
        }
    }

    public function test_the_forms_page_offers_the_card_to_members_and_the_endpoints_it_needs(): void
    {
        $this->actingAs($this->member);
        $page = app(\App\Filament\Employee\Pages\OperationalForms::class);
        $reflection = new \ReflectionMethod($page, 'libraryLinks');
        $links = $reflection->invoke($page);

        $card = collect($links)->firstWhere('key', 'narcotics-check');
        $this->assertNotNull($card, 'the Narcotics check card must be in the Forms library');
        $this->assertSame('Narcotics check', $card['title']);
        $this->assertTrue($card['in_app']);

        $endpoints = (new \ReflectionMethod($page, 'narcoticsEndpoints'))->invoke($page);
        $this->assertSame(url('/api/narcotics-checks/form'), url($endpoints['form']));
        $this->assertSame('__UUID__', $endpoints['placeholder']);

        config()->set('narcotics_checks.enabled', false);
        $this->assertNull((new \ReflectionMethod($page, 'narcoticsEndpoints'))->invoke($page));
        $this->assertNull(collect($reflection->invoke($page))->firstWhere('key', 'narcotics-check'));
    }
}

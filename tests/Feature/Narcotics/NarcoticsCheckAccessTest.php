<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Narcotics\NarcoticsCheck;
use App\Services\Narcotics\NarcoticsCheckAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

/** Who may send, who may review, who may see a photo. Every denial is checked on the server. */
final class NarcoticsCheckAccessTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    private NarcoticsCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
        $this->check = $this->submitOk($this->member);
    }

    private function access(): NarcoticsCheckAccess
    {
        return app(NarcoticsCheckAccess::class);
    }

    public function test_only_captain_5_and_the_protected_super_admin_can_review(): void
    {
        $this->assertTrue($this->access()->canReview($this->captain5));
        $this->assertTrue($this->access()->canReview($this->superAdmin));

        // A Captain-rank officer can open the Captain 5 page, and an Admin Panel administrator can too: neither reviews.
        $this->assertFalse($this->access()->canReview($this->officer));
        $this->assertFalse($this->access()->canReview($this->administrator));
        $this->assertFalse($this->access()->canReview($this->member));
        $this->assertFalse($this->access()->canReview($this->otherMember));
        $this->assertFalse($this->access()->canReview(null));
    }

    public function test_the_staff_api_is_closed_to_everyone_else_with_a_403_and_the_member_never_sees_the_inbox(): void
    {
        foreach ([$this->member, $this->otherMember, $this->officer, $this->administrator] as $user) {
            $this->actingAs($user)->getJson('/api/narcotics-checks/staff/checks')->assertStatus(403);
            $this->actingAs($user)->getJson("/api/narcotics-checks/staff/checks/{$this->check->uuid}")->assertStatus(403);
            $this->approve($user, $this->check)->assertStatus(403);
            $this->reject($user, $this->check, 'no')->assertStatus(403);
        }
        $this->assertSame(NarcoticsCheck::PENDING, $this->check->fresh()->status);

        $this->signOut();
        $this->getJson('/api/narcotics-checks/staff/checks')->assertStatus(401);
    }

    public function test_the_tab_probe_answers_plain_false_to_people_who_do_not_get_the_tab(): void
    {
        foreach ([$this->member, $this->officer, $this->administrator] as $user) {
            $this->actingAs($user)->getJson('/api/narcotics-checks/staff/meta')
                ->assertOk()
                ->assertJsonPath('data.permissions.review', false)
                ->assertJsonPath('data.pending_count', 0);
        }

        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/meta')
            ->assertOk()
            ->assertJsonPath('data.permissions.review', true)
            ->assertJsonPath('data.pending_count', 1);
    }

    public function test_the_second_captain_5_seat_holder_and_an_explicit_allow_can_review(): void
    {
        $second = $this->emsMember('Captain', 'Second Five');
        $this->assertFalse($this->access()->canReview($second));
        $this->emsCaptain5Seat($second);
        $this->assertTrue($this->access()->canReview($second->fresh()));

        // An administrator allowed on the Captain 5 page (Captain 5 Operations) may review.
        Captain5AccessOverride::query()->create([
            'employee_profile_id' => $this->officer->employee_profile_id, 'effect' => Captain5AccessOverride::EFFECT_ALLOW,
            'level' => 'act', 'reason' => 'Covering Captain 5', 'set_by_user_id' => $this->superAdmin->id,
        ]);
        $this->forgetEmsMemo();
        $this->assertTrue($this->access()->canReview($this->officer->fresh()));
    }

    public function test_a_captain_5_deny_override_removes_review_but_not_the_protected_super_admin(): void
    {
        Captain5AccessOverride::query()->create([
            'employee_profile_id' => $this->captain5->employee_profile_id, 'effect' => Captain5AccessOverride::EFFECT_DENY,
            'level' => null, 'reason' => 'Removed from Captain 5', 'set_by_user_id' => $this->superAdmin->id,
        ]);
        $this->forgetEmsMemo();

        $this->assertFalse($this->access()->canReview($this->captain5->fresh()));
        $this->approve($this->captain5->fresh(), $this->check)->assertStatus(403);
        $this->assertTrue($this->access()->canReview($this->superAdmin->fresh()));
    }

    public function test_a_departed_or_locked_account_cannot_review(): void
    {
        DB::table('users')->where('id', $this->captain5->id)->update(['must_change_password' => true]);
        $this->assertFalse($this->access()->canReview($this->captain5->fresh()));
    }

    // ------------------------------------------------------------------ photos

    public function test_the_member_and_captain_5_can_open_every_size_of_the_photo(): void
    {
        foreach ([$this->member, $this->captain5] as $user) {
            foreach (['thumb', 'preview', 'full'] as $variant) {
                $response = $this->actingAs($user)->get("/api/narcotics-checks/{$this->check->uuid}/image/{$variant}")->assertOk();
                $this->assertSame($this->check->image_mime, $response->headers->get('Content-Type'));
                $this->assertNotEmpty($response->getContent());
                $this->assertNotFalse(imagecreatefromstring($response->getContent()), 'the body must be the picture');
            }
        }
    }

    public function test_the_photo_response_cannot_be_cached_sniffed_framed_or_indexed(): void
    {
        $response = $this->actingAs($this->member)->get("/api/narcotics-checks/{$this->check->uuid}/image/full")->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($this->check->uuid, (string) $response->headers->get('Content-Disposition'));
    }

    public function test_another_member_an_officer_and_an_administrator_get_404_for_the_photo(): void
    {
        foreach ([$this->otherMember, $this->officer, $this->administrator] as $user) {
            foreach (['thumb', 'preview', 'full'] as $variant) {
                $this->actingAs($user)->get("/api/narcotics-checks/{$this->check->uuid}/image/{$variant}")->assertNotFound();
            }
        }
    }

    public function test_a_signed_out_visitor_never_gets_the_photo(): void
    {
        $this->signOut();
        $this->get("/api/narcotics-checks/{$this->check->uuid}/image/full", ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_an_unknown_check_an_unknown_size_and_a_purged_photo_are_all_the_same_404(): void
    {
        $this->actingAs($this->captain5);
        $this->get('/api/narcotics-checks/'.Str::uuid().'/image/full')->assertNotFound();
        $this->get("/api/narcotics-checks/{$this->check->uuid}/image/original")->assertNotFound();
        $this->get('/api/narcotics-checks/not-a-uuid/image/full')->assertNotFound();

        $this->check->forceFill(['images_purged_at' => now()])->save();
        $this->get("/api/narcotics-checks/{$this->check->uuid}/image/full")->assertNotFound();
    }

    public function test_the_photo_is_not_reachable_through_the_public_storage_url(): void
    {
        foreach ([$this->check->image_path, $this->check->thumb_path] as $path) {
            $status = $this->get('/storage/'.$path)->getStatusCode();
            $this->assertContains($status, [403, 404], 'the private path must not be served: got '.$status);
        }
    }

    public function test_a_member_cannot_see_a_reviewers_view_of_their_own_history(): void
    {
        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$this->check->uuid}")->assertOk();

        $history = $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$this->check->uuid}")->assertOk()->json('data.history');
        $this->assertSame(['submitted'], array_column($history, 'action'), 'which reviewer opened the photo is audit data, not the member\'s');
    }
}

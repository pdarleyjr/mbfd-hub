<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Services\Narcotics\NarcoticsImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

/** Retention hook, schema and the Captain 5 deep link. */
final class NarcoticsCheckOperationsTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_without_a_retention_window_nothing_is_ever_removed(): void
    {
        $check = $this->submitOk($this->member);
        $this->approve($this->captain5, $check)->assertOk();
        Carbon::setTestNow(now()->addYears(5));

        $this->artisan('narcotics-checks:prune-images --force')->expectsOutputToContain('No narcotics check photo retention window is set')->assertSuccessful();

        $this->assertCount(3, $this->storedFiles());
        $this->assertNull($check->fresh()->images_purged_at);
    }

    public function test_the_prune_is_a_dry_run_unless_forced_and_only_touches_decided_old_checks(): void
    {
        config()->set('narcotics_checks.retention_days', 30);
        $old = $this->submitOk($this->member);
        $oldPending = $this->submitOk($this->member);
        $this->approve($this->captain5, $old)->assertOk();
        Carbon::setTestNow(now()->addDays(31));
        $recent = $this->submitOk($this->otherMember);
        $this->approve($this->captain5, $recent)->assertOk();

        $this->artisan('narcotics-checks:prune-images')->expectsOutputToContain('Dry run: 1 narcotics check(s)')->assertSuccessful();
        $this->assertCount(9, $this->storedFiles(), 'a dry run removes nothing');

        $this->artisan('narcotics-checks:prune-images --force')->expectsOutputToContain('Removed the photo files of 1 narcotics check(s)')->assertSuccessful();

        $files = $this->storedFiles();
        $this->assertCount(6, $files);
        $disk = Storage::disk(NarcoticsImageStore::disk());
        $this->assertFalse($disk->exists($old->image_path));
        $this->assertTrue($disk->exists($oldPending->image_path), 'a check still waiting for Captain 5 is never purged');
        $this->assertTrue($disk->exists($recent->image_path), 'a recent decision is kept');

        // The record, the decision and the audit trail stay; only the picture is gone.
        $row = $old->fresh();
        $this->assertNotNull($row->images_purged_at);
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->captain5->id, $row->decided_by_user_id);
        $this->assertSame(1, NarcoticsCheckEvent::query()->where('narcotics_check_id', $old->id)->where('action', 'images_purged')->count());
        $this->assertSame(1, NarcoticsCheckEvent::query()->where('narcotics_check_id', $old->id)->where('action', 'approved')->count());

        $this->actingAs($this->member)->get("/api/narcotics-checks/{$old->uuid}/image/full")->assertNotFound();
        $detail = $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$old->uuid}")->assertOk();
        $detail->assertJsonPath('data.image.available', false)->assertJsonPath('data.image.full_url', null)->assertJsonPath('data.status', 'approved');

        // A second run finds nothing more to do.
        $this->artisan('narcotics-checks:prune-images --force')->expectsOutputToContain('Removed the photo files of 0 narcotics check(s)')->assertSuccessful();
    }

    public function test_the_migration_is_additive_and_safe_to_run_twice(): void
    {
        $this->assertTrue(Schema::hasTable('narcotics_checks'));
        $this->assertTrue(Schema::hasTable('narcotics_check_events'));
        $before = NarcoticsCheck::query()->count();

        (require database_path('migrations/2026_10_30_140000_create_narcotics_check_tables.php'))->up();

        $this->assertSame($before, NarcoticsCheck::query()->count());
        $columns = Schema::getColumnListing('narcotics_checks');
        foreach (['uuid', 'status', 'client_submission_id', 'resubmission_of_id', 'image_path', 'archived_at', 'decision_note', 'images_purged_at'] as $column) {
            $this->assertContains($column, $columns);
        }
        // No URL or public path is ever stored.
        $this->assertNotContains('image_url', $columns);
    }

    public function test_the_migration_creates_no_permission_and_grants_none(): void
    {
        $permissions = \Illuminate\Support\Facades\DB::table('permissions')->where('name', 'like', '%narcotic%')->count();
        $this->assertSame(0, $permissions, 'review is decided by Captain5Access sources, never by a new grant');
    }

    public function test_deep_links_carry_only_the_check_id(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->assertSame("/daily/captain-5?tab=narcotics&check={$uuid}", \App\Support\Narcotics\NarcoticsLinks::staff($uuid));
        $this->assertSame("/employee/forms?form=narcotics-check&check={$uuid}", \App\Support\Narcotics\NarcoticsLinks::member($uuid));
        $this->assertSame('/employee/forms?form=narcotics-check', \App\Support\Narcotics\NarcoticsLinks::memberForm());
        $this->assertSame('/employee/forms?form=narcotics-check&view=mine', \App\Support\Narcotics\NarcoticsLinks::memberChecks());
    }

    public function test_the_forms_page_reads_its_narcotics_deep_link_from_the_query_string(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $page = app(\App\Filament\Employee\Pages\OperationalForms::class);
        $method = new \ReflectionMethod($page, 'narcoticsDeepLink');

        $this->actingAs($this->member);
        $this->app['request']->query->replace(['form' => 'narcotics-check', 'check' => strtoupper($uuid)]);
        $this->assertSame(['view' => 'detail', 'check' => $uuid], $method->invoke($page));

        $this->app['request']->query->replace(['form' => 'narcotics-check', 'view' => 'mine']);
        $this->assertSame(['view' => 'mine', 'check' => null], $method->invoke($page));

        $this->app['request']->query->replace(['form' => 'narcotics-check', 'check' => 'not-a-uuid']);
        $this->assertSame(['view' => 'form', 'check' => null], $method->invoke($page));

        $this->app['request']->query->replace(['form' => 'medical-inventory-request', 'check' => $uuid]);
        $this->assertSame(['view' => null, 'check' => null], $method->invoke($page));
    }
}

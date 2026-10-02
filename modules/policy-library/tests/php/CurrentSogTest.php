<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\PdfService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class CurrentSogTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->privateRoot = sys_get_temp_dir().'/mbfd-current-sog-'.bin2hex(random_bytes(8));
        mkdir($this->privateRoot.'/input', 0700, true);
        config(['policy-library.storage_root' => $this->privateRoot.'/private', 'policy-library.pin_hash' => Hash::make('current-sog-test-pin')]);
        $this->app->instance(PdfService::class, new class extends PdfService
        {
            public function inspect(string $path): array
            {
                return ['page_count' => 2, 'sha256' => hash_file('sha256', $path)];
            }

            public function pageText(string $path): array
            {
                return ['First source page', 'Second source page'];
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->privateRoot);
        parent::tearDown();
    }

    public function test_replacing_sogs_retires_old_viewer_downloads_and_aliases_without_changing_medical(): void
    {
        $imports = app(ImportService::class);
        $old = $imports->stage(['manuals' => [$this->manual('sogs', 'Previous SOGs'), $this->manual('medical-protocols', 'Medical')]], $this->privateRoot.'/input');
        foreach ($old as $edition) {
            $imports->publish($edition, null);
        }
        $oldNode = $old[0]->nodes()->where('type', 'document')->firstOrFail();
        $oldRevision = $oldNode->currentRevision;
        $medical = Manual::query()->where('slug', 'medical-protocols')->firstOrFail();
        $medicalBefore = [$medical->active_edition_id, $medical->updated_at->toIso8601String()];
        $medicalRevision = $old[1]->nodes()->where('type', 'document')->firstOrFail()->currentRevision;
        $medicalBytes = file_get_contents(app(RevisionService::class)->path($medicalRevision->storage_path));

        $next = $imports->stage(['manuals' => [$this->manual('sogs', 'Current SOGs'), $this->controls()]], $this->privateRoot.'/input');
        $this->assertSame($old[0]->id, $old[0]->manual->fresh()->active_edition_id);
        $this->assertFalse($next[1]->manual->is_active);
        foreach ($next as $edition) {
            $imports->publish($edition, null);
        }
        $this->member();
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonCount(2, 'manuals')
            ->assertJsonPath('manuals.0.active_edition_id', $next[0]->id);
        $tree = $this->getJson('https://files.mbfdhub.com/api/manuals/sogs/tree')->assertOk();
        $tree->assertJsonPath('manual.active_edition_id', $next[0]->id);
        $tree->assertJsonPath('nodes.0.children.0.revision.metadata.asset_id', 'SECTION-800');
        $tree->assertJsonPath('nodes.0.children.0.revision.metadata.primary_entries.1.id', '800.16');
        $this->assertArrayNotHasKey('semantic_text', $tree->json('nodes.0.children.0.revision.metadata.primary_entries.0'));
        $this->assertSame('archived', $old[0]->fresh()->state);
        $this->getJson('https://files.mbfdhub.com/api/nodes/'.$oldNode->id.'/document')->assertNotFound();
        foreach (['', '/download', '/canonical'] as $suffix) {
            $this->getJson('https://files.mbfdhub.com/assets/'.$oldRevision->uuid.$suffix)->assertNotFound();
        }
        $this->getJson('https://files.mbfdhub.com/api/search?q=Previous%20SOGs')->assertOk()->assertJsonCount(0, 'results');
        $medical->refresh();
        $this->assertSame($medicalBefore, [$medical->active_edition_id, $medical->updated_at->toIso8601String()]);
        $this->assertSame($medicalBytes, file_get_contents(app(RevisionService::class)->path($medicalRevision->storage_path)));
        $this->assertFileExists(app(RevisionService::class)->path($oldRevision->storage_path));
    }

    public function test_current_peer_routes_require_pin_validate_pages_and_keep_controls_admin_only(): void
    {
        $imports = app(ImportService::class);
        $editions = $imports->stage(['manuals' => [$this->manual('sogs', 'Current SOGs'), $this->controls()]], $this->privateRoot.'/input');
        foreach ($editions as $edition) {
            $imports->publish($edition, null);
        }
        $this->getJson('https://files.mbfdhub.com/current-sog/SECTION-800?page=2')->assertUnauthorized();
        $user = $this->member(false);
        $this->getJson('https://files.mbfdhub.com/current-sog/SECTION-800?page=2')->assertForbidden();
        $this->grant($user);
        $this->get('https://files.mbfdhub.com/current-sog/SECTION-800?page=2')->assertRedirect('/?manual=sogs&node=asset-section-800-current-sogs&page=2');
        foreach (['0', '-1', '1.5', '10001', '2e0', '01'] as $page) {
            $this->getJson('https://files.mbfdhub.com/current-sog/SECTION-800?page='.$page)->assertUnprocessable();
        }
        $this->getJson('https://files.mbfdhub.com/current-sog/SECTION-800?page=3')->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/current-sog/unknown?page=1')->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/current-sog/MBFD-Master-Index?page=1')->assertForbidden();
        $control = $editions[1]->nodes()->where('type', 'document')->firstOrFail()->currentRevision;
        $this->getJson('https://files.mbfdhub.com/assets/'.$control->uuid)->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/assets/'.$control->uuid.'/canonical')->assertNotFound();
        Permission::findOrCreate('files.manage', 'web');
        $user->givePermissionTo('files.manage');
        $this->withSession(['policy-library.access' => null]);
        $this->get('https://files.mbfdhub.com/current-sog/MBFD-Master-Index?page=2')->assertRedirect('/manage/revisions/'.$control->uuid.'/preview#page=2');
        $this->get('https://files.mbfdhub.com/manage/revisions/'.$control->uuid.'/canonical')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=document.pdf');
    }

    public function test_served_and_canonical_downloads_bind_current_different_hashes_and_preserve_range_privacy(): void
    {
        $imports = app(ImportService::class);
        $edition = $imports->stage(['manuals' => [$this->manual('sogs', 'Current SOGs')]], $this->privateRoot.'/input')[0];
        $imports->publish($edition, null);
        $revision = $edition->nodes()->where('type', 'document')->firstOrFail()->currentRevision;
        $this->assertNotSame($revision->sha256, $revision->metadata['canonical_sha256']);
        $this->member();
        $this->get('https://files.mbfdhub.com/assets/'.$revision->uuid.'/download')->assertOk()
            ->assertHeader('ETag', '"'.$revision->sha256.'"')->assertHeader('Content-Disposition', 'attachment; filename=document.pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Cloudflare-CDN-Cache-Control', 'no-store');
        $this->withHeader('Range', 'bytes=0-9')->get('https://files.mbfdhub.com/assets/'.$revision->uuid.'/canonical')
            ->assertStatus(206)->assertHeader('ETag', '"'.$revision->metadata['canonical_sha256'].'"')
            ->assertHeader('Content-Range', 'bytes 0-9/'.filesize(app(RevisionService::class)->path($revision->source_path)));
    }

    public function test_primary_search_and_two_identical_legacy_numbers_resolve_distinct_subjects(): void
    {
        $imports = app(ImportService::class);
        $edition = $imports->stage(['manuals' => [$this->manual('sogs', 'Current SOGs')]], $this->privateRoot.'/input')[0];
        $imports->publish($edition, null);
        $node = $edition->nodes()->where('type', 'document')->firstOrFail();
        $this->member();
        $this->getJson('https://files.mbfdhub.com/api/search?q=800.16')->assertOk()->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.primary_id', '800.16')->assertJsonPath('results.0.page', 2)->assertJsonPath('results.0.node_id', $node->id);
        $results = $this->getJson('https://files.mbfdhub.com/api/search?q=800.01')->assertOk()->json('results');
        $aliases = array_values(array_filter($results, fn (array $result): bool => isset($result['subject_alias'])));
        $this->assertCount(2, $aliases);
        $this->assertSame(['LEGACY-800-A', 'LEGACY-800-B'], array_column(array_column($aliases, 'subject_alias'), 'source_record_id'));
        $this->assertSame(['800.01', '800.16'], array_column($aliases, 'primary_id'));
        $this->getJson('https://files.mbfdhub.com/api/search?q=incident%20command')->assertOk()->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.primary_id', '800.01')->assertJsonPath('results.0.page', 1);
        $this->getJson('https://files.mbfdhub.com/api/search?q=semantic%20phrase')->assertOk()->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.primary_id', '800.16')->assertJsonPath('results.0.page', 2)
            ->assertJsonPath('results.0.semantic_page_count', 2)->assertJsonPath('results.0.title', 'Command Board');
    }

    public function test_canonical_hash_mismatch_rolls_back_staging_and_preserves_existing_manual_visibility(): void
    {
        $imports = app(ImportService::class);
        $current = $imports->stage(['manuals' => [$this->manual('sogs', 'Current SOGs')]], $this->privateRoot.'/input')[0];
        $imports->publish($current, null);
        $entry = $this->manual('sogs', 'Invalid SOGs');
        $entry['is_active'] = false;
        $entry['sections'][0]['documents'][0]['metadata']['canonical_sha256'] = str_repeat('f', 64);
        try {
            $imports->stage(['manuals' => [$entry]], $this->privateRoot.'/input');
            $this->fail('Canonical checksum mismatch was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, $current->manual->editions()->count());
            $this->assertTrue($current->manual->fresh()->is_active);
            $this->assertSame($current->id, $current->manual->fresh()->active_edition_id);
        }
    }

    public function test_combined_publish_command_rolls_back_both_manuals_when_second_edition_fails_integrity(): void
    {
        $imports = app(ImportService::class);
        $current = $imports->stage(['manuals' => [$this->manual('sogs', 'Previous SOGs'), $this->controls(), $this->manual('medical-protocols', 'Medical')]], $this->privateRoot.'/input');
        foreach ($current as $edition) {
            $imports->publish($edition, null);
        }
        $medical = $current[2]->manual->fresh();
        $medicalBefore = [$medical->active_edition_id, $medical->updated_at->toIso8601String()];
        $nextControls = $this->manual('sog-review-controls', 'Next review control');
        $nextControls['type'] = 'other';
        $nextControls['is_active'] = false;
        $nextControls['metadata']['admin_only'] = true;
        $filename = basename($nextControls['sections'][0]['documents'][0]['asset_path']);
        DocumentRevision::created(function (DocumentRevision $revision) use ($filename): void {
            if ($revision->source_filename === $filename) {
                file_put_contents(app(RevisionService::class)->path($revision->storage_path), '\n', FILE_APPEND);
            }
        });
        $path = $this->privateRoot.'/input/atomic-publish.json';
        file_put_contents($path, json_encode(['manuals' => [$this->manual('sogs', 'Next SOGs'), $nextControls]], JSON_THROW_ON_ERROR));
        $this->artisan('policy-library:import', ['manifest' => $path, '--publish' => true])
            ->expectsOutput('Import failed. Current published editions were preserved; any staged drafts remain private.')->assertFailed();
        foreach ([$current[0], $current[1]] as $edition) {
            $this->assertSame($edition->id, $edition->manual->fresh()->active_edition_id);
            $this->assertSame('published', $edition->fresh()->state);
            $next = $edition->manual->editions()->latest('id')->firstOrFail();
            $this->assertSame('draft', $next->state);
            $this->assertSame('draft', $next->nodes()->where('type', 'document')->firstOrFail()->currentRevision->state);
        }
        $medical->refresh();
        $this->assertSame($medicalBefore, [$medical->active_edition_id, $medical->updated_at->toIso8601String()]);
    }

    private function manual(string $slug, string $label): array
    {
        $filename = str_replace(' ', '-', strtolower($label));
        $source = '%PDF-1.7 canonical '.$label;
        $served = '%PDF-1.7 served '.$label;
        file_put_contents($this->privateRoot.'/input/'.$filename.'.pdf', $source);
        file_put_contents($this->privateRoot.'/input/'.$filename.'-served.pdf', $served);
        $entries = $slug === 'sogs' ? [
            ['id' => '800.01', 'title' => 'Incident Command', 'parent' => null, 'anchor' => 'MBFD_800_01', 'physical_page' => 1, 'slug' => '800-01-incident-command', 'semantic_pages' => [1], 'semantic_text' => 'Incident command source '.$label],
            ['id' => '800.16', 'title' => 'Command Board', 'parent' => '800.01', 'anchor' => 'MBFD_800_16', 'physical_page' => 2, 'slug' => '800-16-command-board', 'semantic_pages' => [1, 2], 'semantic_text' => 'Command board source '.$label.' embedded semantic phrase'],
        ] : [];
        $aliases = $slug === 'sogs' ? [
            ['source_record_id' => 'LEGACY-800-A', 'legacy_id' => '800.01', 'source_title' => 'Historical Incident Organization', 'source_pages' => [1], 'current_ids' => ['800.01']],
            ['source_record_id' => 'LEGACY-800-B', 'legacy_id' => '800.01', 'source_title' => 'Historical Board Procedure', 'source_pages' => [9], 'current_ids' => ['800.16']],
        ] : [];

        return ['slug' => $slug, 'name' => $slug === 'sogs' ? 'SOGs' : 'Medical Protocols', 'type' => $slug === 'sogs' ? 'sog' : 'medical', 'version_label' => $label,
            'metadata' => ['review_edition' => 'MBFD-TEST-R2'], 'sections' => [['title' => 'Operations', 'slug' => 'operations', 'documents' => [[
                'title' => $label, 'slug' => 'asset-section-800-'.$filename, 'asset_path' => $filename.'-served.pdf', 'source_path' => $filename.'.pdf',
                'sha256' => hash('sha256', $served), 'page_count' => 2, 'node_metadata' => ['asset_id' => 'SECTION-800'],
                'metadata' => ['asset_id' => 'SECTION-800', 'review_edition' => 'MBFD-TEST-R2', 'canonical_sha256' => hash('sha256', $source), 'primary_entries' => $entries, 'subject_aliases' => $aliases],
                'pages' => [['physical_page' => 1, 'text' => 'Incident command source '.$label], ['physical_page' => 2, 'text' => 'Command board source '.$label.' embedded semantic phrase']],
            ]]]]];
    }

    private function controls(): array
    {
        $entry = $this->manual('sog-review-controls', 'Review control');
        $entry['name'] = 'SOG Review Controls';
        $entry['type'] = 'other';
        $entry['is_active'] = false;
        $entry['metadata']['admin_only'] = true;
        $entry['sections'][0]['documents'][0]['node_metadata']['asset_id'] = 'MBFD-Master-Index';
        $entry['sections'][0]['documents'][0]['metadata']['asset_id'] = 'MBFD-Master-Index';

        return $entry;
    }

    private function member(bool $pin = true): User
    {
        $token = bin2hex(random_bytes(6));
        $employee = Employee::query()->create(['employee_id' => 'SOG-'.$token, 'name' => 'Current SOG Member', 'rank' => 'Firefighter', 'password' => 'not-used-by-tests', 'must_change_password' => false, 'city_email' => $token.'@miamibeachfl.gov']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => AccountStatus::Active, 'must_change_password' => false, 'email' => $employee->city_email])->load('employeeProfile');
        $this->actingAs($user);
        if ($pin) {
            $this->grant($user);
        }

        return $user;
    }

    private function grant(User $user): void
    {
        $this->withSession(['policy-library.access' => ['user_id' => (string) $user->id, 'expires_at' => now()->addHour()->timestamp, 'pin_version' => hash('sha256', (string) config('policy-library.pin_hash'))]]);
    }
}

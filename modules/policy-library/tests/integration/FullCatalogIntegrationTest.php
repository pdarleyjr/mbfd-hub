<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Models\PageMetadata;
use Mbfd\PolicyLibrary\PolicyLibraryServiceProvider;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FullCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require MBFD_POLICY_HUB_FIXTURE_ROOT.'/bootstrap/app.php';
        $app->afterBootstrapping(RegisterProviders::class, static function (Application $app): void {
            $app->register(PolicyLibraryServiceProvider::class);
        });
        $this->traitsUsedByTest = array_fill_keys(array_keys(class_uses_recursive(self::class)), 1);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_full_latest_source_catalog_stages_hidden_then_publishes_with_original_assets_and_page_metadata(): void
    {
        $manifestPath = getenv('MBFD_POLICY_CATALOG_MANIFEST');
        $qpdf = getenv('TEST_QPDF_BINARY');
        $pdfinfo = getenv('TEST_PDFINFO_BINARY');
        self::assertNotFalse($manifestPath, 'Supply the audited source manifest for this explicit full-catalog gate.');
        self::assertFileExists($manifestPath);
        self::assertNotFalse($qpdf);
        self::assertFileExists($qpdf);
        self::assertNotFalse($pdfinfo);
        self::assertFileExists($pdfinfo);
        $this->withoutVite();
        $pin = bin2hex(random_bytes(8));
        config([
            'policy-library.pin_hash' => Hash::make($pin),
            'policy-library.pin_ttl_minutes' => 5,
            'policy-library.scanner_enabled' => false,
            'policy-library.qpdf_binary' => $qpdf,
            'policy-library.pdfinfo_binary' => $pdfinfo,
            'policy-library.storage_root' => MBFD_POLICY_HUB_FIXTURE_ROOT.'/var/catalog-cases/'.bin2hex(random_bytes(8)),
        ]);
        $manifestHash = hash_file('sha256', $manifestPath);
        $expectedManifestHash = getenv('MBFD_POLICY_CATALOG_EXPECTED_SHA256') ?: '1e56ede2212d14169c4f17dce8fd07cbc9aa8cc46e85dc6ca8b4a9da7be9b03c';
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expectedManifestHash);
        self::assertSame($expectedManifestHash, $manifestHash);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $manifest['manuals']);
        self::assertSame(['sogs', 'medical-protocols'], array_column($manifest['manuals'], 'slug'));
        $documents = [];
        $walk = static function (array $sections) use (&$walk, &$documents): void {
            foreach ($sections as $section) {
                array_push($documents, ...($section['documents'] ?? []));
                $walk($section['children'] ?? []);
            }
        };
        foreach ($manifest['manuals'] as $manual) {
            $walk($manual['sections']);
        }
        self::assertCount(448, $documents);
        $totalPages = array_sum(array_column($documents, 'page_count'));
        self::assertSame(1391, $totalPages);
        foreach ($manifest['audits'] as $audit) {
            if (! isset($audit['logical_document'])) {
                continue;
            }
            self::assertSame(max(array_column($audit['candidates'], 'version')), $audit['selected']['version']);
            self::assertContains($audit['selected']['sha256'], array_column($manifest['sources'], 'sha256'));
        }
        $email = 'policy.catalog.'.bin2hex(random_bytes(8)).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => 'POLICY-'.bin2hex(random_bytes(4)), 'name' => 'Policy Catalog Fixture',
            'rank' => 'Firefighter', 'city_email' => $email,
            'password' => bin2hex(random_bytes(24)), 'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active, 'email' => $email, 'must_change_password' => false,
        ]);
        Permission::findOrCreate('files.manage', 'web');
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertForbidden();
        $this->post('https://files.mbfdhub.com/access', ['pin' => $pin])->assertRedirect();
        $this->withCookie((string) config('session.cookie'), session()->getId())->withCredentials();
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonCount(0, 'manuals');
        $service = app(ImportService::class);
        $stageStarted = hrtime(true);
        $editions = $service->stage($manifest, dirname($manifestPath), $user->id);
        $stageElapsedSeconds = (hrtime(true) - $stageStarted) / 1e9;
        file_put_contents(config('policy-library.storage_root').'/stage-timing.json', json_encode([
            'status' => 'STAGED', 'manifest_sha256' => $manifestHash,
            'stage_elapsed_seconds' => $stageElapsedSeconds, 'worker_timeout_seconds' => 1800,
            'scanner_enabled' => false,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        self::assertCount(2, $editions);
        self::assertSame(448, DocumentRevision::query()->count());
        self::assertSame(1391, PageMetadata::query()->count());
        self::assertSame(448, ManualNode::query()->where('type', 'document')->count());
        self::assertCount(13, $manifest['sources']);
        $archivedSources = DocumentRevision::query()->distinct()->pluck('source_path')->all();
        self::assertCount(13, $archivedSources);
        $archivedHashes = array_map(static fn (string $path): string => basename($path, '.pdf'), $archivedSources);
        $expectedSources = array_column($manifest['sources'], 'sha256');
        sort($archivedHashes);
        sort($expectedSources);
        self::assertSame($expectedSources, $archivedHashes);
        foreach ($archivedSources as $relative) {
            self::assertSame(basename($relative, '.pdf'), hash_file('sha256', config('policy-library.storage_root').'/'.$relative));
        }
        // Long-running imports may outlast the member PIN grant; refresh through the genuine route.
        $this->post('https://files.mbfdhub.com/access', ['pin' => $pin])->assertRedirect();
        $this->withCookie((string) config('session.cookie'), session()->getId())->withCredentials();
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonCount(0, 'manuals');
        // Failure late in publication must roll back earlier revision changes.
        $lastNode = $editions[0]->nodes()->where('type', 'document')->orderByDesc('id')->with('currentRevision')->firstOrFail();
        $privatePath = config('policy-library.storage_root').'/'.$lastNode->currentRevision->storage_path;
        $originalBytes = file_get_contents($privatePath);
        file_put_contents($privatePath, "\n", FILE_APPEND);
        try {
            try {
                $service->publish($editions[0], $user->id);
                self::fail('Publication accepted a document whose private checksum changed.');
            } catch (ValidationException) {
                self::assertSame(0, DocumentRevision::query()->where('state', 'published')->count());
                self::assertNull($editions[0]->manual->fresh()->active_edition_id);
                self::assertSame('draft', $editions[0]->fresh()->state);
            }
        } finally {
            file_put_contents($privatePath, $originalBytes);
        }
        foreach ($editions as $edition) {
            $service->publish($edition, $user->id);
        }
        $catalog = $this->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonCount(2, 'manuals');
        self::assertSame(['sogs', 'medical-protocols'], array_column($catalog->json('manuals'), 'slug'));
        $trees = app(TreeService::class);
        foreach (Manual::query()->orderBy('sort_order')->get() as $manual) {
            $tree = $trees->tree($manual);
            $expected = $manual->slug === 'sogs' ? 140 : 308;
            self::assertCount($expected, $tree['documents']);
            self::assertSame('published', $manual->activeEdition->state);
        }
        foreach (['100-xx-gov-a1-policy-change-and-publication-routing', '100-xx-gov-f1-change-transfer-release-record'] as $slug) {
            $companion = ManualNode::query()->where('slug', $slug)->with('currentRevision.pages')->firstOrFail();
            self::assertSame(2, $companion->currentRevision->page_count);
            self::assertSame('governance-companions', $companion->parent->slug);
            foreach ($companion->currentRevision->pages as $page) {
                self::assertContains($page->physical_page, [10, 11, 12, 13]);
            }
        }
        $medical = Manual::query()->where('slug', 'medical-protocols')->firstOrFail();
        $updates = $medical->nodes()->where('edition_id', $medical->active_edition_id)->where('slug', 'updates-inserts')->firstOrFail();
        self::assertSame(['medications-rocephin-ceftriaxone', 'procedures-point-of-care-ultrasound-pocus-miami-beach'], $updates->metadata['related_document_slugs']);
        foreach ($updates->metadata['related_document_slugs'] as $slug) {
            $canonical = $medical->nodes()->where('edition_id', $medical->active_edition_id)->where('slug', $slug)->get();
            self::assertCount(1, $canonical);
            self::assertContains($canonical->first()->id, $trees->tree($medical)['documents']);
        }
        foreach (DocumentRevision::query()->with('pages')->get() as $revision) {
            self::assertSame('published', $revision->state);
            self::assertCount($revision->page_count, $revision->pages);
            self::assertSame($revision->sha256, hash_file('sha256', config('policy-library.storage_root').'/'.$revision->storage_path));
            self::assertFileExists(config('policy-library.storage_root').'/'.$revision->source_path);
            self::assertSame(range(1, $revision->page_count), $revision->pages->pluck('page')->all());
        }
        $sample = DocumentRevision::query()->firstOrFail();
        $length = filesize(config('policy-library.storage_root').'/'.$sample->storage_path);
        $this->withHeader('Range', 'bytes=0-127')->get('https://files.mbfdhub.com/assets/'.$sample->uuid)
            ->assertStatus(206)->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Range', 'bytes 0-127/'.$length)
            ->assertHeader('Cloudflare-CDN-Cache-Control', 'no-store');
        self::assertSame($manifestHash, hash_file('sha256', $manifestPath));
        file_put_contents(config('policy-library.storage_root').'/catalog-gate.json', json_encode([
            'status' => 'PASS', 'fixture_source_sha' => trim((string) file_get_contents(MBFD_POLICY_HUB_FIXTURE_ROOT.'/.policy-fixture-source-sha')),
            'manifest_sha256' => $manifestHash, 'manuals' => 2, 'documents' => 448,
            'serving_pages' => 1391, 'original_archives' => 13,
            'stage_elapsed_seconds' => $stageElapsedSeconds, 'worker_timeout_seconds' => 1800,
            'scanner_enabled' => false, 'database' => 'isolated SQLite',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}

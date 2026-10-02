<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Explicit frozen-source gate; its private source PDFs are deliberately outside ordinary CI. */
final class R2CatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->privateRoot = sys_get_temp_dir().'/mbfd-r2-catalog-'.bin2hex(random_bytes(8));
        config(['policy-library.storage_root' => $this->privateRoot, 'policy-library.scanner_enabled' => false,
            'policy-library.qpdf_binary' => getenv('TEST_QPDF_BINARY') ?: 'qpdf',
            'policy-library.pdfinfo_binary' => getenv('TEST_PDFINFO_BINARY') ?: 'pdfinfo',
            'policy-library.pin_hash' => Hash::make('r2-catalog-test-pin')]);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateRoot)) {
            (new Filesystem)->deleteDirectory($this->privateRoot);
        }
        parent::tearDown();
    }

    public function test_frozen_r2_full_assets_publish_current_only_with_complete_identities_aliases_and_private_originals(): void
    {
        $path = getenv('MBFD_POLICY_R2_MANIFEST') ?: dirname(__DIR__, 4).'/var/r2-staged/import-manifest.json';
        self::assertFileExists($path, 'Supply the already-frozen R2 manifest; this gate never generates source assets.');
        $manifestHash = hash_file('sha256', $path);
        $expectedHash = getenv('MBFD_POLICY_R2_EXPECTED_SHA256');
        if ($expectedHash !== false && $expectedHash !== '') {
            self::assertSame($expectedHash, $manifestHash);
        }
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['sogs', 'sog-review-controls'], array_column($manifest['manuals'], 'slug'));
        self::assertSame('MBFD-COORDINATED-20261002-R2', $manifest['manuals'][0]['metadata']['review_edition']);
        self::assertFalse($manifest['manuals'][1]['is_active']);
        self::assertTrue($manifest['manuals'][1]['metadata']['admin_only']);
        $sogDocuments = $this->documents($manifest['manuals'][0]['sections']);
        $controls = $this->documents($manifest['manuals'][1]['sections']);
        self::assertCount(32, $sogDocuments);
        self::assertCount(2, $controls);
        self::assertSame(887, array_sum(array_column($sogDocuments, 'page_count')));
        self::assertSame(39, array_sum(array_column($controls, 'page_count')));

        // A small prior-edition and clinical fixture prove retirement/preservation without reimporting the old catalog or MOMS.
        $baselineDocument = collect($sogDocuments)->sortBy('page_count')->first();
        $baselineDocument['slug'] = 'previous-source';
        $baselineDocument['node_metadata']['asset_id'] = 'PREVIOUS-SOG-SOURCE';
        $baselineDocument['metadata']['asset_id'] = 'PREVIOUS-SOG-SOURCE';
        $baselineDocument['metadata']['primary_entries'] = [];
        $baselineDocument['metadata']['subject_aliases'] = [];
        $baseline = ['slug' => 'sogs', 'name' => 'SOGs', 'type' => 'sog', 'version_label' => 'Previous native fixture',
            'sections' => [['title' => 'Previous', 'slug' => 'previous', 'documents' => [$baselineDocument]]]];
        $clinical = $baseline;
        $clinical['slug'] = 'medical-protocols';
        $clinical['name'] = 'Preserved clinical fixture';
        $clinical['type'] = 'medical';
        $imports = app(ImportService::class);
        $before = $imports->stage(['manuals' => [$baseline, $clinical]], dirname($path));
        foreach ($before as $edition) {
            $imports->publish($edition, null);
        }
        $oldNode = $before[0]->nodes()->where('type', 'document')->firstOrFail();
        $oldRevision = $oldNode->currentRevision;
        $medical = $before[1]->manual->fresh();
        $medicalState = [$medical->active_edition_id, $medical->updated_at->toIso8601String()];
        $medicalRevision = $before[1]->nodes()->where('type', 'document')->firstOrFail()->currentRevision;
        $medicalHash = hash_file('sha256', app(RevisionService::class)->path($medicalRevision->storage_path));

        $started = hrtime(true);
        $editions = $imports->stage($manifest, dirname($path));
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertSame($before[0]->id, $before[0]->manual->fresh()->active_edition_id);
        self::assertSame('draft', $editions[0]->fresh()->state);
        self::assertFalse($editions[1]->manual->is_active);
        foreach ($editions as $edition) {
            $imports->publish($edition, null);
        }
        $sogNodes = $editions[0]->nodes()->where('type', 'document')->with('currentRevision.pages')->get();
        $controlNodes = $editions[1]->nodes()->where('type', 'document')->with('currentRevision.pages')->get();
        self::assertCount(32, $sogNodes);
        self::assertCount(2, $controlNodes);
        self::assertSame(887, $sogNodes->sum(fn ($node): int => $node->currentRevision->page_count));
        self::assertSame(39, $controlNodes->sum(fn ($node): int => $node->currentRevision->page_count));
        $expectedByAsset = collect([...$sogDocuments, ...$controls])->keyBy(fn (array $document): string => $document['metadata']['asset_id']);
        $nodesByAsset = $sogNodes->concat($controlNodes)->keyBy(fn ($node): string => $node->metadata['asset_id']);
        $identities = [];
        $aliases = [];
        $canonicalHashes = [];
        $peerLinks = [];
        foreach ($nodesByAsset as $assetId => $node) {
            $revision = $node->currentRevision;
            $expected = $expectedByAsset[$assetId];
            self::assertSame('published', $revision->state);
            self::assertSame($expected['sha256'], $revision->sha256);
            self::assertSame($expected['metadata']['canonical_sha256'], hash_file('sha256', app(RevisionService::class)->path($revision->source_path)));
            self::assertSame($revision->sha256, hash_file('sha256', app(RevisionService::class)->path($revision->storage_path)));
            self::assertSame($expected['page_count'], $revision->page_count);
            self::assertSame(range(1, $revision->page_count), $revision->pages->pluck('physical_page')->all());
            self::assertSame('Not issued', $revision->metadata['issue_status']);
            self::assertNull($revision->metadata['approval_event']);
            self::assertNull($revision->metadata['effective_date']);
            foreach ($revision->metadata['primary_entries'] as $entry) {
                self::assertArrayNotHasKey($entry['id'], $identities);
                self::assertNotEmpty($entry['semantic_pages']);
                self::assertSame(array_values(array_unique($entry['semantic_pages'])), $entry['semantic_pages']);
                foreach ($entry['semantic_pages'] as $page) {
                    self::assertIsInt($page);
                    self::assertGreaterThanOrEqual(1, $page);
                    self::assertLessThanOrEqual($revision->page_count, $page);
                }
                $identities[$entry['id']] = $entry;
            }
            foreach ($revision->metadata['subject_aliases'] as $alias) {
                $aliases[$alias['source_record_id']] = $alias;
            }
            foreach ($revision->metadata['peer_links'] as $peer) {
                $peerLinks[] = ['source_asset_id' => $assetId, 'source_page_count' => $revision->page_count] + $peer;
            }
            $canonicalHashes[$assetId] = $revision->metadata['canonical_sha256'];
        }
        self::assertCount(347, $identities);
        self::assertCount(130, $aliases);
        self::assertCount(1685, $peerLinks);
        foreach ($identities as $entry) {
            if ($entry['parent'] !== null) {
                self::assertArrayHasKey($entry['parent'], $identities);
            }
        }
        foreach ($aliases as $alias) {
            foreach ($alias['current_ids'] as $id) {
                self::assertArrayHasKey($id, $identities);
            }
        }
        self::assertCount(2, array_filter($aliases, fn (array $alias): bool => $alias['legacy_id'] === '800.01'));
        foreach ($peerLinks as $peer) {
            self::assertArrayHasKey($peer['target_asset_id'], $canonicalHashes);
            self::assertSame($canonicalHashes[$peer['target_asset_id']], $peer['target_source_sha256']);
            self::assertSame($nodesByAsset[$peer['target_asset_id']]->slug, $peer['target_slug']);
            self::assertGreaterThanOrEqual(1, $peer['target_page']);
            self::assertLessThanOrEqual($nodesByAsset[$peer['target_asset_id']]->currentRevision->page_count, $peer['target_page']);
            self::assertGreaterThanOrEqual(1, $peer['source_page']);
            self::assertLessThanOrEqual($peer['source_page_count'], $peer['source_page']);
        }

        $member = $this->member();
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonCount(2, 'manuals');
        $tree = $this->getJson('https://files.mbfdhub.com/api/manuals/sogs/tree')->assertOk();
        self::assertCount(32, $tree->json('documents'));
        $this->getJson('https://files.mbfdhub.com/api/manuals/sog-review-controls/tree')->assertNotFound();
        foreach ($sogNodes as $node) {
            $this->get('https://files.mbfdhub.com/current-sog/'.$node->metadata['asset_id'].'?page='.$node->currentRevision->page_count)
                ->assertRedirect('/?manual=sogs&node='.$node->slug.'&page='.$node->currentRevision->page_count);
        }
        foreach ($controlNodes as $node) {
            $this->getJson('https://files.mbfdhub.com/current-sog/'.$node->metadata['asset_id'].'?page=1')->assertForbidden();
            $this->getJson('https://files.mbfdhub.com/assets/'.$node->currentRevision->uuid.'/canonical')->assertNotFound();
        }
        foreach (['', '/download', '/canonical'] as $suffix) {
            $this->getJson('https://files.mbfdhub.com/assets/'.$oldRevision->uuid.$suffix)->assertNotFound();
        }
        $this->getJson('https://files.mbfdhub.com/current-sog/PREVIOUS-SOG-SOURCE?page=1')->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/api/nodes/'.$oldNode->id.'/document')->assertNotFound();
        self::assertSame('archived', $before[0]->fresh()->state);
        $medical->refresh();
        self::assertSame($medicalState, [$medical->active_edition_id, $medical->updated_at->toIso8601String()]);
        self::assertSame($medicalHash, hash_file('sha256', app(RevisionService::class)->path($medicalRevision->storage_path)));
        Permission::findOrCreate('files.manage', 'web');
        $member->givePermissionTo('files.manage');
        foreach ($controlNodes as $node) {
            $this->get('https://files.mbfdhub.com/current-sog/'.$node->metadata['asset_id'].'?page=1')
                ->assertRedirect('/manage/revisions/'.$node->currentRevision->uuid.'/preview#page=1');
            $this->get('https://files.mbfdhub.com/manage/revisions/'.$node->currentRevision->uuid.'/canonical')->assertOk();
        }
        $sample = $sogNodes->first()->currentRevision;
        $this->withHeader('Range', 'bytes=0-127')->get('https://files.mbfdhub.com/assets/'.$sample->uuid.'/canonical')
            ->assertStatus(206)->assertHeader('ETag', '"'.$sample->metadata['canonical_sha256'].'"')->assertHeader('Cloudflare-CDN-Cache-Control', 'no-store');
        self::assertSame($manifestHash, hash_file('sha256', $path));
        file_put_contents(dirname(__DIR__, 4).'/var/r2-catalog-test-receipt.json', json_encode([
            'status' => 'PASS', 'manifest_sha256' => $manifestHash, 'database' => 'isolated SQLite',
            'sog_assets' => 32, 'sog_physical_pages' => 887, 'review_controls' => 2, 'review_control_pages' => 39,
            'primary_identities' => count($identities), 'subject_aliases' => count($aliases), 'peer_links' => count($peerLinks),
            'canonical_sha256' => $canonicalHashes, 'stage_elapsed_seconds' => $elapsed,
            'old_current_sources' => 0, 'medical_fixture_unchanged' => true, 'scanner_enabled' => false,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function documents(array $sections): array
    {
        $documents = [];
        foreach ($sections as $section) {
            array_push($documents, ...($section['documents'] ?? []), ...$this->documents($section['children'] ?? []));
        }

        return $documents;
    }

    private function member(): User
    {
        $token = bin2hex(random_bytes(6));
        $employee = Employee::query()->create(['employee_id' => 'R2-'.$token, 'name' => 'R2 Catalog Member', 'rank' => 'Firefighter', 'password' => 'not-used-by-tests', 'must_change_password' => false, 'city_email' => $token.'@miamibeachfl.gov']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => AccountStatus::Active, 'must_change_password' => false, 'email' => $employee->city_email])->load('employeeProfile');
        $this->actingAs($user)->withSession(['policy-library.access' => ['user_id' => (string) $user->id, 'expires_at' => now()->addHour()->timestamp, 'pin_version' => hash('sha256', (string) config('policy-library.pin_hash'))]]);

        return $user;
    }
}

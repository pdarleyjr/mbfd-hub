<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\ManualDeliveryService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Tests\TestCase;

/** Synthetic authorization/cache fixtures; actual PDF fidelity is separately reviewed. */
final class PolicyLibraryManualDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->privateRoot = sys_get_temp_dir().'/mbfd-manual-delivery-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->privateRoot.'/revisions', 0700, true);
        config(['policy-library.storage_root' => $this->privateRoot, 'policy-library.accel_prefix' => null,
            'policy-library.pin_hash' => password_hash('synthetic-delivery-pin', PASSWORD_BCRYPT)]);
        $this->app->instance(ManualDeliveryService::class, new class(app(TreeService::class), app(RevisionService::class)) extends ManualDeliveryService
        {
            protected function inspect(string $pdf, int $pages): void
            {
                // This test isolates guards; it does not claim synthetic bytes pass qpdf/ClamAV.
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateRoot);
        parent::tearDown();
    }

    private function manual(string $slug = 'sogs'): Manual
    {
        $manual = Manual::query()->create(['slug' => $slug, 'name' => 'Synthetic complete manual', 'type' => 'sog']);
        $edition = $manual->editions()->create(['label' => 'Synthetic current', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $section = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Visible section', 'slug' => 'section', 'type' => 'section']);
        $items = $slug === 'sogs' ? ['one' => 'individual_sog', 'two' => 'individual_sog', 'companion' => 'controlled_companion', 'book' => 'full_section'] : ['one' => 'protocol', 'two' => 'protocol'];
        foreach ($items as $order => $type) {
            $node = $edition->nodes()->create(['manual_id' => $manual->id, 'parent_id' => $section->id,
                'slug' => $order, 'title' => $order, 'type' => 'document', 'metadata' => ['asset_id' => $order, 'search_role' => $type === 'full_section' ? 'aggregate' : 'leaf']]);
            $bytes = '%PDF- Synthetic source '.$order;
            $hash = hash('sha256', $bytes);
            File::put($this->privateRoot.'/revisions/'.$hash.'.pdf', $bytes);
            $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'synthetic.pdf',
                'storage_path' => 'revisions/'.$hash.'.pdf', 'sha256' => $hash, 'page_count' => 2, 'state' => 'published',
                'metadata' => ['asset_id' => $order, 'asset_type' => $type, 'search_role' => $type === 'full_section' ? 'aggregate' : 'leaf', 'leaf_asset_ids' => ['one', 'two']]]);
            $node->update(['current_revision_id' => $revision->id]);
        }

        return $manual->fresh();
    }

    private function manifest(Manual $manual): array
    {
        $bytes = '%PDF- Complete synthetic delivery fixture';
        $qa = 'Synthetic guard-only receipt, not an actual PDF inspection';
        File::put($this->privateRoot.'/incoming.pdf', $bytes);
        File::put($this->privateRoot.'/qa.json', $qa);

        return app(ManualDeliveryService::class)->snapshot($manual) + ['schema' => 'mbfd-manual-delivery-v1',
            'sha256' => hash('sha256', $bytes), 'byte_size' => strlen($bytes), 'page_count' => 4, 'front_pages' => 0,
            'independent_qa_sha256' => hash('sha256', $qa)];
    }

    private function install(Manual $manual): array
    {
        $manifest = $this->manifest($manual);
        app(ManualDeliveryService::class)->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json');

        return $manifest;
    }

    private function member(): void
    {
        $user = $this->actingAsCanonicalFixture('QA-MANUAL-DELIVERY', 'QA Manual Delivery');
        $this->withSession(['policy-library.access' => ['user_id' => (string) $user->id,
            'expires_at' => now()->timestamp + 60, 'pin_version' => hash('sha256', (string) config('policy-library.pin_hash'))]]);
    }

    public function test_complete_sog_includes_primary_documents_once_and_install_does_not_modify_revisions(): void
    {
        $manual = $this->manual();
        $before = $manual->nodes()->with('currentRevision')->get()->toArray();
        $manifest = $this->install($manual);
        self::assertSame(['one', 'two'], array_column($manifest['sources'], 'slug'));
        self::assertSame($before, $manual->nodes()->with('currentRevision')->get()->toArray());
        self::assertSame($manual->active_edition_id, $manual->fresh()->active_edition_id);
        // Idempotent exact preparation does not rewrite any immutable artifact or manifest.
        self::assertSame($manifest, app(ManualDeliveryService::class)->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json'));
        $this->member();
        $base = 'https://files.mbfdhub.com/manuals/sogs/pdf?edition='.$manual->active_edition_id;
        $this->get($base)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'inline; filename=MBFD-Complete-SOGs.pdf');
        $this->get($base.'&download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=MBFD-Complete-SOGs.pdf');
        $this->get($base, ['Range' => 'bytes=0-4'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-4/'.$manifest['byte_size']);
        $this->getJson('https://files.mbfdhub.com/api/manuals/sogs/tree')->assertOk()->assertJsonPath('delivery.page_count', 4)->assertJsonPath('delivery.byte_size', $manifest['byte_size']);
    }

    public function test_complete_medical_routes_use_the_same_canonical_identity_and_pin_gates(): void
    {
        $manual = $this->manual('medical-protocols');
        $this->install($manual);
        $url = 'https://files.mbfdhub.com/manuals/medical-protocols/pdf?edition='.$manual->active_edition_id;
        $this->getJson($url)->assertUnauthorized();
        $this->actingAsCanonicalFixture('QA-MANUAL-PIN', 'QA Manual PIN');
        $this->getJson($url)->assertForbidden();
        $this->member();
        $this->get($url)->assertOk();
        $this->get($url.'&download=1')->assertHeader('Content-Disposition', 'attachment; filename=MBFD-Complete-Medical-Protocols.pdf');
        $this->get('https://files.mbfdhub.com/manuals/sog-review-controls/pdf?edition='.$manual->active_edition_id)->assertNotFound();
        $this->get('https://www.mbfdhub.com/manuals/medical-protocols/pdf?edition='.$manual->active_edition_id)->assertNotFound();
    }

    public function test_hidden_ancestor_or_changed_source_withholds_the_prepared_complete_pdf(): void
    {
        $manual = $this->manual();
        $this->install($manual);
        $this->member();
        $url = 'https://files.mbfdhub.com/manuals/sogs/pdf?edition='.$manual->active_edition_id;
        $section = $manual->nodes()->where('type', 'section')->firstOrFail();
        $section->update(['is_active' => false]);
        $this->get($url)->assertNotFound();
        $section->update(['is_active' => true]);
        $this->get($url)->assertOk();
        $leaf = $manual->nodes()->where('slug', 'one')->firstOrFail();
        $leaf->currentRevision->update(['state' => 'draft']);
        $this->get($url)->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/api/manuals/sogs/tree')->assertOk()->assertJsonPath('delivery', null);
    }

    public function test_new_current_edition_cannot_use_an_old_delivery_even_if_the_pdf_is_still_stored(): void
    {
        $manual = $this->manual();
        $this->install($manual);
        $this->member();
        $old = $manual->active_edition_id;
        $next = $manual->editions()->create(['label' => 'Next synthetic edition', 'state' => 'published']);
        $manual->update(['active_edition_id' => $next->id]);
        $this->get('https://files.mbfdhub.com/manuals/sogs/pdf?edition='.$old)->assertNotFound();
        $this->get('https://files.mbfdhub.com/manuals/sogs/pdf?edition='.$next->id)->assertNotFound();
    }

    public function test_same_size_artifact_corruption_is_detected_and_source_corruption_blocks_installation(): void
    {
        $manual = $this->manual();
        $manifest = $this->install($manual);
        $this->member();
        File::put($this->privateRoot.'/deliveries/files/'.$manifest['sha256'].'.pdf', str_repeat('x', $manifest['byte_size']));
        $this->get('https://files.mbfdhub.com/manuals/sogs/pdf?edition='.$manual->active_edition_id)->assertNotFound();
        $revision = $manual->nodes()->where('slug', 'one')->firstOrFail()->currentRevision;
        File::put($this->privateRoot.'/'.$revision->storage_path, 'Source corruption');
        $this->expectException(\RuntimeException::class);
        app(ManualDeliveryService::class)->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json');
    }

    public function test_wrong_scope_or_missing_independent_receipt_cannot_create_a_delivery(): void
    {
        $manual = $this->manual();
        $manifest = $this->manifest($manual);
        array_pop($manifest['sources']);
        try {
            app(ManualDeliveryService::class)->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json');
            self::fail('Incomplete source scope was accepted.');
        } catch (\RuntimeException) {
            self::assertDirectoryDoesNotExist($this->privateRoot.'/deliveries');
        }
        $manifest = $this->manifest($manual);
        File::put($this->privateRoot.'/qa.json', 'Changed receipt');
        $this->expectException(\RuntimeException::class);
        app(ManualDeliveryService::class)->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json');
    }

    private function interruptedInstallation(string $failure): void
    {
        $manual = $this->manual();
        $manifest = $this->manifest($manual);
        $service = new class(app(TreeService::class), app(RevisionService::class), $failure) extends ManualDeliveryService
        {
            public function __construct(TreeService $trees, RevisionService $storage, private ?string $failure)
            {
                parent::__construct($trees, $storage);
            }

            protected function inspect(string $pdf, int $pages): void {}

            protected function copyInto(string $source, $output): void
            {
                if ($this->failure === 'copy') {
                    $this->failure = null;
                    fwrite($output, '%PDF- partial');
                    throw new \RuntimeException('Simulated interruption after actual staging bytes were written.');
                }
                parent::copyInto($source, $output);
            }

            protected function writeManifestInto(string $encoded, $output): void
            {
                if ($this->failure === 'manifest') {
                    $this->failure = null;
                    fwrite($output, '{"partial":');
                    throw new \RuntimeException('Simulated interruption after actual manifest staging bytes were written.');
                }
                parent::writeManifestInto($encoded, $output);
            }
        };
        try {
            $service->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json');
            self::fail('Injected write interruption did not occur.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Simulated interruption', $exception->getMessage());
        }
        $manifestPath = $this->privateRoot.'/deliveries/'.$manual->id.'/'.$manual->active_edition_id.'/'.$manifest['catalog_sha256'].'.json';
        self::assertFileDoesNotExist($manifestPath);
        if ($failure === 'copy') {
            self::assertFileDoesNotExist($this->privateRoot.'/deliveries/files/'.$manifest['sha256'].'.pdf');
        }
        self::assertSame([], glob($this->privateRoot.'/deliveries/files/.delivery-*.part'));
        self::assertSame([], glob(dirname($manifestPath).'/.delivery-*.part'));
        self::assertNull($service->current($manual));
        self::assertSame($manifest, $service->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json'));
        self::assertSame($manifest['sha256'], $service->current($manual, true)['sha256']);
    }

    public function test_interrupted_pdf_copy_leaves_no_final_partial_and_exact_retry_succeeds(): void
    {
        $this->interruptedInstallation('copy');
    }

    public function test_interrupted_manifest_write_leaves_no_final_partial_and_exact_retry_succeeds(): void
    {
        $this->interruptedInstallation('manifest');
    }

    public function test_requested_edition_is_rechecked_after_the_current_delivery_refresh(): void
    {
        $manual = $this->manual();
        $manifest = $this->install($manual);
        $next = $manual->editions()->create(['label' => 'Concurrent next', 'state' => 'published']);
        $artifactPath = $this->privateRoot.'/deliveries/files/'.$manifest['sha256'].'.pdf';
        $this->app->instance(ManualDeliveryService::class, new class(app(TreeService::class), app(RevisionService::class), $manifest, $next->id, $artifactPath) extends ManualDeliveryService
        {
            public function __construct(TreeService $trees, RevisionService $storage, private array $manifest, private int $nextId, private string $path)
            {
                parent::__construct($trees, $storage);
            }

            public function current(Manual $manual, bool $verifyBytes = false): ?array
            {
                // The exact race: the requested edition passed, then a fresh resolver sees publication.
                $manual->update(['active_edition_id' => $this->nextId]);

                return array_merge($this->manifest, ['edition_id' => $this->nextId, 'path' => $this->path]);
            }
        });
        $this->member();
        $this->get('https://files.mbfdhub.com/manuals/sogs/pdf?edition='.$manifest['edition_id'])->assertNotFound();
    }

    public function test_a_linked_delivery_ancestor_is_refused_without_writing_through_it(): void
    {
        $manual = $this->manual();
        $manifest = $this->manifest($manual);
        File::makeDirectory($this->privateRoot.'/outside-deliveries');
        if (! @symlink($this->privateRoot.'/outside-deliveries', $this->privateRoot.'/deliveries')) {
            $this->markTestSkipped('This host does not allow creation of symbolic links; Linux CI exercises the ancestor guard.');
        }
        try {
            app(ManualDeliveryService::class)->install($manual, $manifest, $this->privateRoot.'/incoming.pdf', $this->privateRoot.'/qa.json');
            self::fail('A linked ancestor was accepted.');
        } catch (\RuntimeException) {
            self::assertSame([], File::files($this->privateRoot.'/outside-deliveries'));
            self::assertNull(app(ManualDeliveryService::class)->current($manual));
        } finally {
            unlink($this->privateRoot.'/deliveries');
        }
    }
}

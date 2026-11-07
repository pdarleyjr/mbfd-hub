<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\ChecklistRevisionStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * The checklist template editor on real PostgreSQL (production's database): the json columns return the
 * template byte-for-byte (so a published version hashes exactly as it was previewed), the partial unique
 * indexes allow one draft and one published version per checklist, and a publish / revert runs with its
 * row locks inside one transaction. Each test runs inside a transaction that is rolled back. Guarded to the
 * explicitly configured disposable test database.
 */
#[Group('postgres')]
final class ChecklistTemplatePostgresTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDisposablePostgres();
        DB::beginTransaction();
        $this->setUpInventoryFleet();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_postgresql_stores_publishes_and_reverts_a_template_without_changing_its_hash(): void
    {
        $editor = app(ChecklistTemplateEditor::class);
        $resolver = app(DailyCheckoutChecklistResolver::class);
        $manager = $this->logistics();
        $before = $this->resolved('E1');

        $draft = $editor->startDraft('engine', $manager);
        $form = $editor->toForm($draft->checklist);
        $form['compartments'][0]['items'][0]['manufacturer'] = 'Panasonic';
        $form['compartments'][0]['items'][0]['expectedQuantity'] = 2;
        $draft = $editor->saveDraft($draft, $form, $manager, $draft->lock_version);

        // The json column gives back exactly what was hashed.
        $stored = Version::query()->findOrFail($draft->getKey());
        self::assertSame($resolver->versionOf($stored->checklist), $stored->checklist_hash);

        $preview = $editor->preview($stored);
        self::assertTrue($preview['publishable'], implode(' ', $preview['errors']));
        $published = $editor->publish($stored, $manager, 'Toughbook brand and count', $preview['fingerprint'], $stored->lock_version);

        self::assertSame(1, $published->version_number);
        $after = $this->resolved('E1');
        self::assertNotSame($before['checklist_version'], $after['checklist_version']);
        self::assertSame('Panasonic', $after['checklist']['compartments'][0]['items'][0]['manufacturer']);
        // Work started on the previous checklist is still accepted.
        self::assertNotNull(app(ChecklistRevisionStore::class)->recentlyRetired((int) $this->unit('E1')->getKey(), $before['checklist_version']));
        self::assertSame($this->resolved('E2')['checklist_base_version'], $resolver->versionOf(json_decode((string) file_get_contents(base_path('storage/checklists/engine2_checklist.json')), true, 512, JSON_THROW_ON_ERROR)));

        $revert = $editor->previewRevertToFile('engine');
        self::assertTrue($revert['publishable'], implode(' ', $revert['errors']));
        $editor->revertToFile('engine', $manager, 'Back to the repository file', $revert['fingerprint']);
        self::assertSame($before['checklist_version'], $this->resolved('E1')['checklist_version']);
    }

    public function test_postgresql_allows_one_draft_and_one_published_version_per_checklist(): void
    {
        $editor = app(ChecklistTemplateEditor::class);
        $draft = $editor->startDraft('ladder1', $this->logistics());

        DB::statement('SAVEPOINT second_draft');
        try {
            Version::query()->create([
                'checklist_type' => 'ladder1', 'status' => Version::STATUS_DRAFT, 'checklist' => $draft->checklist,
                'checklist_hash' => $draft->checklist_hash, 'based_on_hash' => $draft->based_on_hash,
            ]);
            self::fail('A second draft of one checklist must be refused.');
        } catch (UniqueConstraintViolationException) {
            DB::statement('ROLLBACK TO SAVEPOINT second_draft');
        }

        // Another checklist may have its own draft.
        self::assertSame('ladder3', $editor->startDraft('ladder3', $this->logistics())->checklist_type);
        self::assertSame(1, Version::query()->where('checklist_type', 'ladder1')->where('status', Version::STATUS_DRAFT)->count());
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing')
            && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql'
            && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }
        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('PostgreSQL integration tests require the explicit loopback disposable database configuration.');
        }
        $this->markTestSkipped('This regression requires the explicitly configured disposable PostgreSQL test database.');
    }
}

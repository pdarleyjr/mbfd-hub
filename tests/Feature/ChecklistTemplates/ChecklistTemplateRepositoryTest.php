<?php

declare(strict_types=1);

namespace Tests\Feature\ChecklistTemplates;

use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use App\Services\ChecklistTemplates\ChecklistTemplateRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The published-template memo of ChecklistTemplateRepository (docs/checklist-template-editor.md section 3),
 * on a connection of its own with no wrapping test transaction, as production requests run: memoized outside
 * transactions, read fresh inside one, and a read inside a transaction drops the memo.
 */
final class ChecklistTemplateRepositoryTest extends TestCase
{
    private const CONNECTION = 'checklist_template_memo';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.'.self::CONNECTION, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge(self::CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        parent::tearDown();
    }

    public function test_a_database_without_the_table_has_no_published_version(): void
    {
        $this->assertNull(app(ChecklistTemplateRepository::class)->published('engine', self::CONNECTION));
    }

    public function test_the_published_row_is_memoized_outside_transactions_and_read_fresh_inside(): void
    {
        Schema::connection(self::CONNECTION)->create('daily_checkout_checklist_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('checklist_type');
            $table->string('status');
            $table->unsignedInteger('version_number')->nullable();
            $table->json('checklist');
            $table->timestamps();
        });
        $repository = app(ChecklistTemplateRepository::class);
        $this->assertSame(0, DB::connection(self::CONNECTION)->transactionLevel());

        $this->assertNull($repository->published('engine', self::CONNECTION));
        $this->publishRow(1);

        // Outside a transaction the request keeps one consistent baseline.
        $this->assertNull($repository->published('engine', self::CONNECTION));

        // Inside one (lock, then resolve again), a publish that committed meanwhile is seen.
        $inside = DB::connection(self::CONNECTION)->transaction(fn (): ?Version => $repository->published('engine', self::CONNECTION));
        $this->assertSame(1, $inside?->version_number);

        // The read inside the transaction dropped the stale memo: the next read outside is fresh and memoized.
        $this->assertSame(1, $repository->published('engine', self::CONNECTION)?->version_number);
        DB::connection(self::CONNECTION)->table('daily_checkout_checklist_template_versions')->update(['status' => Version::STATUS_RETIRED]);
        $this->publishRow(2);
        $this->assertSame(1, $repository->published('engine', self::CONNECTION)?->version_number);

        $repository->flush();
        $this->assertSame(2, $repository->published('engine', self::CONNECTION)?->version_number);
    }

    private function publishRow(int $number): void
    {
        DB::connection(self::CONNECTION)->table('daily_checkout_checklist_template_versions')->insert([
            'checklist_type' => 'engine', 'status' => Version::STATUS_PUBLISHED, 'version_number' => $number,
            'checklist' => json_encode(['compartments' => []]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

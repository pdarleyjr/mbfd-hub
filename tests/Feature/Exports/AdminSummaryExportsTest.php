<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Pages\CommunicationsUsage;
use App\Filament\Pages\HealthCheckResults;
use App\Filament\Pages\PulseDashboard;
use App\Filament\Pages\WorkgroupAdministration;
use App\Models\CloudflareUsageBudget;
use App\Models\SecurityActionEvent;
use App\Models\Workgroup;
use App\Models\WorkgroupSession;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\TableExportService;
use Carbon\CarbonImmutable;
use Laravel\Pulse\Contracts\Storage;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdminSummaryExportsTest extends ExportTestCase
{
    private function snapshots(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
        config(['communications.cloudflare.account_id' => 'export-fixture']);
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => 'export-fixture', 'cycle_start' => '2026-10-01 00:00:00', 'cycle_end' => '2026-11-01 00:00:00',
            'provider_chargeable_used' => 75, 'hub_safe_ceiling' => 2850, 'reconciled_at' => '2026-10-26 11:55:00',
            'provider_daily_used' => 5, 'provider_daily_quota' => 100,
        ]);
        $workgroup = Workgroup::query()->create(['name' => 'Export workgroup', 'is_active' => true]);
        WorkgroupSession::query()->create(['workgroup_id' => $workgroup->id, 'name' => 'Export session', 'status' => 'active', 'start_date' => '2026-10-01', 'end_date' => '2026-11-01']);
        $results = new StoredCheckResults(CarbonImmutable::parse('2026-10-26T11:55:00Z'), collect([
            new StoredCheckResult('Database', 'Database', 'Healthy connection', 'Connected', 'ok', ['secret_metadata' => 'must-not-export']),
        ]));
        $store = Mockery::mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn($results);
        $store->shouldNotReceive('save');
        $this->app->instance(ResultStore::class, $store);
        $pulse = Mockery::mock(Storage::class);
        $pulse->shouldReceive('values')->with('system')->andReturn(collect([(object) [
            'timestamp' => CarbonImmutable::parse('2026-10-26T11:55:00Z')->timestamp,
            'key' => 'fixture-server', 'value' => json_encode(['name' => 'Fixture server', 'cpu' => 30, 'memory_used' => 100, 'memory_total' => 200, 'storage' => [['directory' => '/', 'used' => 10, 'total' => 100]]], JSON_THROW_ON_ERROR),
        ]]));
        $pulse->shouldReceive('graph')->andReturnUsing(fn (array $types) => collect(['fixture-server' => collect([$types[0] => collect(['2026-10-26 11:55:00' => 30])])]));
        $pulse->shouldReceive('aggregate')->andReturnUsing(fn (string $type) => collect([(object) ['key' => 'Fixture metric', 'count' => 2,
            'max' => $type === 'exception' ? CarbonImmutable::parse('2026-10-26T11:55:00Z')->timestamp : 5]]));
        $pulse->shouldNotReceive('store');
        $pulse->shouldNotReceive('purge');
        $pulse->shouldNotReceive('trim');
        $this->app->instance(Storage::class, $pulse);
    }

    #[DataProvider('surfaces')]
    public function test_each_summary_downloads_its_actual_values_to_both_formats(string $source, string $format, string $label): void
    {
        $this->snapshots();
        $component = Livewire::test($source)->callAction('exportData', ['format' => $format, 'minutes' => 60]);
        $bytes = $this->downloadedBytes($component);
        if ($format === 'pdf') {
            $text = (new Parser)->parseContent($bytes)->getText();
        } else {
            $book = $this->workbook($component);
            $text = json_encode($book->getAllSheets()[0]->toArray(null, true, true, false), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                .json_encode($book->getAllSheets()[1]->toArray(null, true, true, false), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        self::assertStringContainsString($label, $text);
        self::assertStringContainsString('Oct 26, 2026 8:00 AM EDT', $text);
        self::assertStringNotContainsString('must-not-export', $text);
        self::assertSame(1, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'allowed')->count());
    }

    #[DataProvider('surfaces')]
    public function test_each_summary_rechecks_native_access_before_reading_data(string $source, string $format, string $label): void
    {
        $page = app($source);
        self::assertTrue($page->canExportData($this->admin));
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse($page->canExportData($limited));
        try {
            app(TableExportService::class)->downloadDataset($limited, $source, $format,
                fn (): bool => $page->canExportData($limited),
                static fn (): ExportDataset => throw new \LogicException('Denied exports must not read data'));
            self::fail('Export unexpectedly allowed.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(1, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'denied')->count());
    }

    public static function surfaces(): array
    {
        $cases = [];
        foreach ([CommunicationsUsage::class => 'Provider usage', WorkgroupAdministration::class => 'Export session', HealthCheckResults::class => 'Healthy connection', PulseDashboard::class => 'Fixture server'] as $source => $label) {
            foreach (['xlsx', 'pdf'] as $format) {
                $cases[] = [$source, $format, $label];
            }
        }

        return $cases;
    }
}

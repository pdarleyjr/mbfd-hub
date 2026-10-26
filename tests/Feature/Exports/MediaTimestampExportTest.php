<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Admin\Pages\MediaControlMedia;
use App\Services\MediaControl\MediaControlContent;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;

final class MediaTimestampExportTest extends ExportTestCase
{
    #[DataProvider('instants')]
    public function test_upstream_media_instants_use_eastern_time_and_handle_missing_or_invalid_dates(?string $value, ?string $expected): void
    {
        self::assertSame($expected, MediaControlContent::createdAt($value)?->format('Y-m-d H:i:s T'));
    }

    public static function instants(): array
    {
        return [
            ['2026-10-26T00:30:00Z', '2026-10-25 20:30:00 EDT'],
            ['2026-03-08T06:59:59Z', '2026-03-08 01:59:59 EST'],
            ['2026-03-08T07:00:00Z', '2026-03-08 03:00:00 EDT'],
            [null, null], ['not-a-timestamp', null],
        ];
    }

    public function test_media_library_and_both_exports_use_the_prior_eastern_day_at_utc_midnight(): void
    {
        config(['services.media_control.base_url' => 'https://media.example.test', 'services.media_control.ingest_token' => 'synthetic-test-token']);
        Http::fake([
            'https://media.example.test/api/hub-ingest/v1/health' => Http::response(['ok' => true]),
            'https://media.example.test/api/hub-ingest/v1/content?*' => Http::response(['items' => [[
                'id' => 'timestamp-fixture', 'title' => 'Synthetic media', 'kind' => 'image', 'mime' => 'image/png',
                'created_at' => '2026-10-26T00:30:00Z',
            ]], 'next_cursor' => null]),
        ]);
        $page = Livewire::test(MediaControlMedia::class)->assertSee('Oct 25, 2026');
        $page->callAction('exportData', ['format' => 'xlsx']);
        $rows = $this->workbook($page)->getSheet(0)->toArray(null, true, true, false);
        self::assertSame('2026-10-25 20:30', $rows[1][9]);
        $page->callAction('exportData', ['format' => 'pdf']);
        $text = (new Parser)->parseContent($this->downloadedBytes($page))->getText();
        self::assertStringContainsString('Oct 25, 2026 8:30 PM', $text);
        Http::assertSentCount(2);
    }
}

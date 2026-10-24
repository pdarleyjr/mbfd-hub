<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** The Captain 5 page downloads a count or reconciliation as Excel or PDF from the staff API (view access). */
final class EmsSnapshotExportApiTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $captain;

    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->captain = $this->emsCaptain('Cap Tain');
        $this->snapshot = $this->finalizeCount($this->captain, ['sr.t.02' => 30, 'sr.t.03' => 1]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function url(string $format, ?string $uuid = null): string
    {
        return '/ems/api/supply/snapshots/'.($uuid ?? $this->snapshot).'/export/'.$format;
    }

    public function test_view_access_downloads_the_excel_file_privately(): void
    {
        $response = $this->actingAs($this->captain)->get($this->url('xlsx'));

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_view_access_downloads_the_pdf_file_privately(): void
    {
        $response = $this->actingAs($this->captain)->get($this->url('pdf'));

        $response->assertOk();
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_a_view_only_admin_may_download_but_a_plain_member_and_a_guest_may_not(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $this->actingAs($viewer)->get($this->url('xlsx'))->assertOk();
        $this->actingAs($viewer)->get($this->url('pdf'))->assertOk();

        $member = $this->emsMember('Firefighter', 'Plain Member');
        foreach (['xlsx', 'pdf'] as $format) {
            $this->actingAs($member)->getJson($this->url($format))->assertForbidden();
        }

        $this->logoutCanonicalSession();
        $this->getJson($this->url('xlsx'))->assertUnauthorized();
        $this->getJson($this->url('pdf'))->assertUnauthorized();
    }

    public function test_an_unknown_count_answers_with_the_standard_not_found_envelope(): void
    {
        $this->actingAs($this->captain)->getJson($this->url('xlsx', '11111111-2222-4333-8444-555555555555'))
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_download_is_only_a_read(): void
    {
        $before = \App\Models\Ems\InventorySnapshot::query()->count();
        $this->actingAs($this->captain)->get($this->url('xlsx'))->assertOk();
        $this->actingAs($this->captain)->get($this->url('pdf'))->assertOk();

        $this->assertSame($before, \App\Models\Ems\InventorySnapshot::query()->count());
        $this->actingAs($this->captain)->post($this->url('xlsx'))->assertStatus(405);
    }
}

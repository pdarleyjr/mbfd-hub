<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Enums\AccountStatus;
use App\Filament\Employee\Pages\OperationalForms;
use App\Filament\Employee\Pages\ResourceLibrary;
use App\Models\ResourceDocument;
use App\Models\User;
use App\Services\ResourceLibrary\ResourceLibrarySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResourceLibraryAccessTest extends TestCase
{
    use BuildsResourceLibraryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResourceLibrary();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->tearDownResourceLibrary();
        parent::tearDown();
    }

    public function test_guests_are_sent_to_sign_in_or_get_401(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));

        $this->get($this->previewUrl($document))->assertRedirect(route('login'));
        $this->get($this->downloadUrl($document))->assertRedirect(route('login'));
        $this->get('/employee/resources')->assertRedirect('/login');
        $this->getJson($this->previewUrl($document))->assertUnauthorized();
        $this->getJson($this->downloadUrl($document))->assertUnauthorized();
    }

    public function test_an_account_without_an_employee_profile_cannot_open_or_download(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));

        $this->get($this->previewUrl($document))->assertForbidden();
        $this->get($this->downloadUrl($document))->assertForbidden();
        self::assertNotSame(200, $this->get('/employee/resources')->getStatusCode());
    }

    public function test_a_member_previews_a_pdf_original_inline_with_private_headers(): void
    {
        $pdf = $this->makePdf('Flow Manual.pdf', 'Hydrant flow');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $pdf);
        $this->actingAsCanonicalFixture();

        $response = $this->get($this->previewUrl($document))->assertOk();

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('Flow Manual.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame((string) filesize($pdf), $response->headers->get('Content-Length'));
        self::assertSame(file_get_contents($pdf), $response->streamedContent());
        self::assertStringNotContainsString('resource-library', implode("\n", array_map(fn ($v) => implode(',', $v), $response->headers->all())));
    }

    public function test_previewing_a_docx_entry_serves_its_pdf_and_download_serves_the_docx(): void
    {
        $docx = $this->makeDocx('2026 Uniform Guideline FINAL.docx', 'Guide');
        $preview = $this->makePdf('2026 Uniform Guideline FINAL.pdf', 'Guide preview');
        $document = $this->importFixture('guide', 'Guide', 'Policies', $docx, $preview);
        $this->actingAsCanonicalFixture();

        $open = $this->get($this->previewUrl($document))->assertOk();
        self::assertSame('application/pdf', $open->headers->get('Content-Type'));
        self::assertStringStartsWith('inline;', (string) $open->headers->get('Content-Disposition'));
        self::assertSame(file_get_contents($preview), $open->streamedContent());

        $download = $this->get($this->downloadUrl($document))->assertOk();
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $download->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        self::assertStringContainsString('2026 Uniform Guideline FINAL.docx', (string) $download->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        self::assertSame(file_get_contents($docx), $download->streamedContent());
    }

    public function test_a_pdf_original_downloads_as_an_attachment_too(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $pdf);
        $this->actingAsCanonicalFixture();

        $download = $this->get($this->downloadUrl($document))->assertOk();

        self::assertSame('application/pdf', $download->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        self::assertSame(file_get_contents($pdf), $download->streamedContent());
    }

    public function test_a_docx_without_a_preview_downloads_but_cannot_be_opened(): void
    {
        $document = $this->importFixture('guide', 'Guide', 'Policies', $this->makeDocx('guide.docx', 'Guide'));
        $this->actingAsCanonicalFixture();

        $this->get($this->previewUrl($document))->assertNotFound();
        $this->get($this->downloadUrl($document))->assertOk();
        $this->get('/employee/resources')->assertOk()
            ->assertSee('Download DOCX')
            ->assertDontSee($this->previewUrl($document), false);
    }

    public function test_unpublished_deleted_and_unknown_documents_are_404(): void
    {
        $hidden = $this->importFixture('hidden', 'Hidden', 'Manuals', $this->makePdf('hidden.pdf', 'Hidden one'), published: false);
        $deleted = $this->importFixture('deleted', 'Deleted', 'Manuals', $this->makePdf('deleted.pdf', 'Deleted one'));
        $deleted->delete();
        $this->actingAsCanonicalFixture();

        foreach ([$hidden, $deleted] as $document) {
            $this->get($this->previewUrl($document))->assertNotFound();
            $this->get($this->downloadUrl($document))->assertNotFound();
        }
        $this->get('/employee/resources/'.fake()->uuid().'/preview')->assertNotFound();
        $this->get('/employee/resources/not-a-uuid/download')->assertNotFound();
    }

    public function test_the_page_lists_published_documents_grouped_with_open_and_download_actions(): void
    {
        $this->importFixture('manual', 'Flow Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow', 2), description: 'Pump hydraulics reference.');
        $this->importFixture('guide', 'Dress Guide', 'Policies', $this->makeDocx('guide.docx', 'Guide'), $this->makePdf('guide.pdf', 'Dress uniform'));
        $this->importFixture('hidden', 'Secret Draft', 'Policies', $this->makePdf('hidden.pdf', 'Not for members'), published: false);
        $this->actingAsCanonicalFixture();

        $response = $this->get('/employee/resources')->assertOk();

        $response->assertSeeInOrder(['Manuals', 'Flow Manual', 'Policies', 'Dress Guide']);
        $response->assertSee('Pump hydraulics reference.');
        $response->assertSee('Download PDF');
        $response->assertSee('Download DOCX');
        $response->assertSee('pages');
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener"', false);
        $response->assertSee('2 documents.');
        $response->assertSee(route('employee.resources.preview', ['document' => ResourceDocument::query()->where('slug', 'manual')->value('uuid')]), false);
        $response->assertSee(route('employee.resources.preview', ['document' => ResourceDocument::query()->where('slug', 'guide')->value('uuid')]), false);
        self::assertSame(2, substr_count($response->getContent(), 'class="rl-btn rl-btn--primary"'), 'Open for the PDF original and for the DOCX with a preview');
        $response->assertDontSee('Secret Draft');
        $response->assertDontSee('resource-library/', false);
    }

    public function test_titles_are_escaped_on_the_page(): void
    {
        $this->importFixture('xss', '<script>alert(1)</script> Manual', 'Manuals', $this->makePdf('xss.pdf', 'Plain text'));
        $this->actingAsCanonicalFixture();

        $this->get('/employee/resources')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Manual', false);
    }

    public function test_search_matches_title_category_description_and_document_text_case_insensitively(): void
    {
        $this->importFixture('flow', 'Flow Manual', 'Manuals', $this->makePdf('flow.pdf', 'Hydrant pressure standard'), description: 'Pump hydraulics reference.');
        $this->importFixture('dress', 'Dress Guide', 'Policies', $this->makePdf('dress.pdf', 'Lanyard placement'), description: 'Ceremonial wear.');
        $search = app(ResourceLibrarySearch::class);

        self::assertEqualsCanonicalizing(['flow', 'dress'], $search->documents()->pluck('slug')->all());
        self::assertSame(['flow'], $search->documents('FLOW')->pluck('slug')->all(), 'title');
        self::assertSame(['dress'], $search->documents('policies')->pluck('slug')->all(), 'category');
        self::assertSame(['dress'], $search->documents('CEREMONIAL')->pluck('slug')->all(), 'description');
        self::assertSame(['flow'], $search->documents('hydrant PRESSURE')->pluck('slug')->all(), 'document text, every word required');
        self::assertSame(['dress'], $search->documents('lanyard')->pluck('slug')->all(), 'document text');
        self::assertSame([], $search->documents('hydrant lanyard')->pluck('slug')->all(), 'words must all match one document');
        self::assertSame([], $search->documents('zzzzqq')->pluck('slug')->all());
        self::assertSame(['dress'], $search->documents('', 'Policies')->pluck('slug')->all(), 'category filter');
        self::assertSame([], $search->documents('hydrant', 'Policies')->pluck('slug')->all());
    }

    public function test_search_treats_percent_and_underscore_literally_and_never_loads_the_text(): void
    {
        $this->importFixture('plain', 'Plain Manual', 'Manuals', $this->makePdf('plain.pdf', 'Nothing special'));
        $this->importFixture('percent', 'Rule 100% Complete', 'Manuals', $this->makePdf('percent.pdf', 'Another document'));
        $search = app(ResourceLibrarySearch::class);

        self::assertSame(['percent'], $search->documents('%')->pluck('slug')->all());
        self::assertSame([], $search->documents('_')->pluck('slug')->all());
        self::assertSame([], $search->documents("' OR 1=1 --")->pluck('slug')->all());
        self::assertArrayNotHasKey('search_text', $search->documents()->first()->getAttributes());
    }

    public function test_the_page_search_and_category_come_from_the_url(): void
    {
        $this->importFixture('flow', 'Flow Manual', 'Manuals', $this->makePdf('flow.pdf', 'Hydrant pressure standard'));
        $this->importFixture('dress', 'Dress Guide', 'Policies', $this->makePdf('dress.pdf', 'Lanyard placement'));
        $this->actingAsCanonicalFixture();

        $this->get('/employee/resources?q=HYDRANT')->assertOk()
            ->assertSee('Flow Manual')->assertDontSee('Dress Guide')->assertSee('1 document matches.');
        $this->get('/employee/resources?category=Policies')->assertOk()
            ->assertSee('Dress Guide')->assertDontSee('Flow Manual');
        $this->get('/employee/resources?q=nothingmatches')->assertOk()
            ->assertSee('No documents match')->assertSee('Clear search');
    }

    public function test_an_empty_library_says_so_plainly(): void
    {
        $this->actingAsCanonicalFixture();

        $this->get('/employee/resources')->assertOk()->assertSee('No documents yet');
    }

    public function test_resources_sits_in_my_work_directly_after_forms(): void
    {
        self::assertSame('Resources', ResourceLibrary::getNavigationLabel());
        self::assertSame('resources', ResourceLibrary::getSlug());
        self::assertSame('My work', ResourceLibrary::getNavigationGroup());
        self::assertSame(OperationalForms::getNavigationSort() + 1, ResourceLibrary::getNavigationSort());
    }

    private function previewUrl(ResourceDocument $document): string
    {
        return route('employee.resources.preview', ['document' => $document->uuid]);
    }

    private function downloadUrl(ResourceDocument $document): string
    {
        return route('employee.resources.download', ['document' => $document->uuid]);
    }
}

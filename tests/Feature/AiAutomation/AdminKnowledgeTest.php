<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Resources\AiKnowledgeArticleResource\Pages\CreateAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\EditAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\ListAiKnowledgeArticles;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

final class AdminKnowledgeTest extends AdminScreensTestCase
{
    private User $admin;

    private KnowledgeLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = app(KnowledgeLibrary::class);
        $this->admin = $this->actingAsSuperAdmin();
    }

    public function test_an_administrator_writes_previews_publishes_and_restores_without_ever_seeing_an_address(): void
    {
        Livewire::test(CreateAiKnowledgeArticle::class)
            ->fillForm(['title' => 'Key fob requests', 'body' => 'Ask the station officer to request a new key fob.', 'member_safe' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = AiKnowledgeArticle::query()->sole();
        self::assertSame('key-fob-requests', $article->slug, 'The address is made automatically.');
        self::assertContains('key fob requests', $article->topic_tags);
        self::assertNull($article->published_version_id);

        $edit = Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getKey()])
            ->assertFormSet(['title' => 'Key fob requests', 'body' => 'Ask the station officer to request a new key fob.'])
            ->assertActionExists('preview')
            ->callAction('publish')
            ->assertHasNoActionErrors();
        $article->refresh();
        self::assertNotNull($article->published_version_id);
        self::assertCount(1, $this->library->retrieve('How do I request a new key fob?'));

        $edit->fillForm(['body' => 'Submit the key fob form to Support Services.', 'change_summary' => 'New process'])->call('save')->assertHasNoFormErrors();
        $edit->callAction('publish');
        self::assertStringContainsString('Support Services', $this->library->retrieve('How do I request a new key fob?')[0]->body);
        self::assertSame(2, $article->versions()->count());

        $firstVersion = $article->versions()->get()->firstWhere('version', 1);
        $edit->callAction('restorePrevious', ['version_id' => $firstVersion->getKey(), 'go_live' => false])->assertHasNoActionErrors();
        self::assertStringContainsString('Support Services', $this->library->retrieve('How do I request a new key fob?')[0]->body, 'Restoring as a draft leaves the live wording alone.');
        self::assertSame(3, $article->versions()->count());
        $draft = $this->library->openDraft($article->fresh());
        self::assertSame('Ask the station officer to request a new key fob.', $draft->body);

        $edit->callAction('publish');
        self::assertStringContainsString('station officer', $this->library->retrieve('How do I request a new key fob?')[0]->body);
    }

    public function test_example_messages_are_saved_and_the_test_a_message_window_explains_which_instructions_match(): void
    {
        Livewire::test(CreateAiKnowledgeArticle::class)
            ->fillForm([
                'title' => 'Key fob requests', 'body' => 'Ask the station officer to request a new key fob from facilities.',
                'sample_phrases' => "how do I get a new key fob\nmy fob stopped working",
            ])
            ->call('create')->assertHasNoFormErrors();
        $article = AiKnowledgeArticle::query()->sole();

        $edit = Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getKey()])
            ->assertFormSet(['sample_phrases' => "how do I get a new key fob\nmy fob stopped working"]);
        $edit->callAction('publish')->assertHasNoActionErrors();

        $matches = app(\App\Services\AiAutomation\Admin\KnowledgeMatchExplainer::class)->forMessage('How do I request a new key fob?');
        self::assertNotEmpty($matches);
        self::assertTrue($matches[0]['used']);
        self::assertSame('Key fob requests', $matches[0]['title']);
        self::assertStringContainsString('Would be used', $matches[0]['why']);
        self::assertStringNotContainsString('key-fob-requests', $matches[0]['why']);
        self::assertSame([], app(\App\Services\AiAutomation\Admin\KnowledgeMatchExplainer::class)->forMessage('   '));

        $edit->mountAction('testMatching')->assertSuccessful();
        $checks = app(\App\Services\AiAutomation\Admin\KnowledgeMatchExplainer::class)->checkVersion($this->library->workingVersion($article->fresh()));
        self::assertContains('how do I get a new key fob', array_column($checks, 'phrase'));
    }

    public function test_restore_previous_can_go_live_right_away(): void
    {
        $article = $this->library->createArticle(['slug' => 'weekend-contact', 'title' => 'Weekend contact', 'body' => 'First wording about the weekend contact.', 'topic_tags' => ['weekend']], $this->admin);
        $this->library->publish($this->library->openDraft($article), $this->admin);
        $this->library->publish($this->library->saveDraft($article->fresh(), ['title' => 'Weekend contact', 'body' => 'Second wording about the weekend contact.'], $this->admin), $this->admin);

        $first = $article->versions()->get()->firstWhere('version', 1);
        Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getKey()])
            ->callAction('restorePrevious', ['version_id' => $first->getKey(), 'go_live' => true])
            ->assertHasNoActionErrors();

        self::assertSame('First wording about the weekend contact.', Version::query()->findOrFail($article->fresh()->published_version_id)->body);
    }

    public function test_the_screens_show_plain_labels_and_no_slug_tag_or_markdown_jargon(): void
    {
        $article = $this->library->createArticle(['slug' => 'secret-internal-slug', 'title' => 'Bunker gear drop-off', 'body' => 'Leave gear at Station 2.', 'topic_tags' => ['bunker']], $this->admin);

        $create = $this->get('/admin/ai-knowledge/create')->assertOk()->getContent();
        $edit = $this->get('/admin/ai-knowledge/'.$article->getKey().'/edit')->assertOk()->getContent();
        $list = $this->get('/admin/ai-knowledge')->assertOk()->getContent();

        foreach ([$create, $edit] as $html) {
            foreach (['What should this say?', 'OK to share with members', 'Use this in every automated answer', 'Start from a file'] as $words) {
                self::assertStringContainsString($words, (string) $html);
            }
            foreach (['Address (slug)', 'Markdown', 'Safe to use in replies', 'Topic tags'] as $jargon) {
                self::assertStringNotContainsString($jargon, (string) $html, $jargon);
            }
        }
        // The page state Livewire carries in an attribute is not something a person reads.
        foreach ([$edit, $list] as $html) {
            self::assertStringNotContainsString('secret-internal-slug', (string) preg_replace('/wire:(?:snapshot|effects)="[^"]*"/', '', (string) $html));
        }
        self::assertStringContainsString('Publish', (string) $edit);
        self::assertStringContainsString('Restore previous', (string) $this->publishedEdit($article));
    }

    public function test_browsing_by_topic_and_searching_the_list(): void
    {
        $gear = $this->library->createArticle(['slug' => 'gear-article', 'title' => 'Bunker gear drop-off', 'body' => 'Leave gear at Station 2.', 'topic_tags' => ['bunker gear']], $this->admin);
        $keys = $this->library->createArticle(['slug' => 'key-article', 'title' => 'Key fob requests', 'body' => 'Ask your officer.', 'topic_tags' => ['key fob']], $this->admin);

        Livewire::test(ListAiKnowledgeArticles::class)->loadTable()
            ->assertCanSeeTableRecords([$gear, $keys])
            ->filterTable('topic', 'gear')
            ->assertCanSeeTableRecords([$gear])->assertCanNotSeeTableRecords([$keys])
            ->resetTableFilters()
            ->searchTable('fob')
            ->assertCanSeeTableRecords([$keys])->assertCanNotSeeTableRecords([$gear]);
    }

    public function test_the_list_uses_plain_status_words_and_exports(): void
    {
        $article = $this->library->createArticle(['slug' => 'a-live-one', 'title' => 'A live one', 'body' => 'Some live wording here.'], $this->admin);
        $this->library->publish($this->library->openDraft($article), $this->admin);

        Livewire::test(ListAiKnowledgeArticles::class)->loadTable()
            ->assertSee('Live')
            ->assertTableActionVisible('export')
            ->assertTableBulkActionExists('export_selected')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'all', 'include_toggled_off' => true])
            ->assertHasNoTableActionErrors();
    }

    public function test_stop_using_this_takes_the_article_out_of_answers(): void
    {
        $article = $this->library->createArticle(['slug' => 'to-stop', 'title' => 'Key fob requests', 'body' => 'Ask the station officer to request a new key fob.', 'topic_tags' => ['key fob']], $this->admin);
        $this->library->publish($this->library->openDraft($article), $this->admin);

        Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getKey()])->callAction('withdraw');

        self::assertNull($article->fresh()->published_version_id);
        self::assertSame([], $this->library->retrieve('How do I request a new key fob?'));
    }

    public function test_starting_from_a_text_file_fills_the_title_and_body_and_keeps_nothing(): void
    {
        $file = UploadedFile::fake()->createWithContent('Weekend Contact Rules.txt', "On Saturday and Sunday call 300.\n\nThere is no admin staff on weekends.");

        Livewire::test(CreateAiKnowledgeArticle::class)
            ->set('data.body_file', [$file])
            ->assertFormSet(['title' => 'Weekend Contact Rules', 'body_file' => null, 'body' => "On Saturday and Sunday call 300.\n\nThere is no admin staff on weekends."]);
    }

    public function test_a_file_that_cannot_be_read_leaves_the_form_alone_and_says_why(): void
    {
        $file = UploadedFile::fake()->createWithContent('malware.exe', 'MZ binary');

        Livewire::test(CreateAiKnowledgeArticle::class)
            ->set('data.title', 'Keep me')
            ->set('data.body_file', [$file])
            ->assertFormSet(['title' => 'Keep me', 'body' => null]);
    }

    private function publishedEdit(AiKnowledgeArticle $article): string
    {
        $this->library->publish($this->library->openDraft($article), $this->admin);
        $this->library->publish($this->library->saveDraft($article->fresh(), ['title' => $article->title, 'body' => 'Changed wording for the drop-off.'], $this->admin), $this->admin);

        return (string) $this->get('/admin/ai-knowledge/'.$article->getKey().'/edit')->assertOk()->getContent();
    }
}

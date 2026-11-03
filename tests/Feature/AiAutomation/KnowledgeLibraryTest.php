<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Enums\AccountStatus;
use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\CreateAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\EditAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\ListAiKnowledgeArticles;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class KnowledgeLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../Fixtures/ai-knowledge-sample.md';

    private KnowledgeLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->library = new KnowledgeLibrary;
    }

    public function test_seed_import_creates_published_articles_once(): void
    {
        self::assertSame(['created' => 2, 'skipped' => 0], $this->library->importSeed(self::FIXTURE));
        self::assertSame(['created' => 0, 'skipped' => 2], $this->library->importSeed(self::FIXTURE));

        $article = AiKnowledgeArticle::query()->where('slug', 'bunker-gear-drop-off')->sole();
        $live = Version::query()->findOrFail($article->published_version_id);
        self::assertSame('Bunker gear drop-off', $article->title);
        self::assertSame(['bunker gear', 'turnout', 'drop-off'], $article->topic_tags);
        self::assertTrue($article->member_safe);
        self::assertSame(Version::STATUS_PUBLISHED, $live->status);
        self::assertSame(1, $live->version);
        self::assertSame('Repository starter guide', $live->created_by_name);
        self::assertSame('Repository starter guide', $live->published_by_name);
        self::assertSame('Seeded from resources/ai/knowledge/mbfd-support-services-guidance.md', $live->source_note);
        self::assertSame('2099-01-01', $live->review_date->toDateString());
        self::assertStringStartsWith('Bunker gear that needs cleaning', $live->body);
        self::assertStringNotContainsString('## ', $live->body);
        self::assertSame(2, AiKnowledgeArticle::query()->count());
    }

    public function test_seed_import_with_a_missing_file_does_nothing(): void
    {
        self::assertSame(['created' => 0, 'skipped' => 0], $this->library->importSeed(__DIR__.'/does-not-exist.md'));
        self::assertSame(0, AiKnowledgeArticle::query()->count());
    }

    public function test_seed_import_never_overwrites_an_edited_or_withdrawn_article(): void
    {
        $this->library->importSeed(self::FIXTURE);
        $editor = $this->user();
        $article = AiKnowledgeArticle::query()->where('slug', 'bunker-gear-drop-off')->sole();
        $draft = $this->library->saveDraft($article, $this->data(['title' => 'Bunker gear drop-off', 'body' => 'Edited by an administrator.']), $editor);
        $this->library->publish($draft, $editor);

        $uniform = AiKnowledgeArticle::query()->where('slug', 'uniform-sizing')->sole();
        $this->library->withdraw($uniform, $editor);

        self::assertSame(['created' => 0, 'skipped' => 2], $this->library->importSeed(self::FIXTURE));
        self::assertSame('Edited by an administrator.', Version::query()->findOrFail($article->fresh()->published_version_id)->body);
        self::assertNull($uniform->fresh()->published_version_id);
        self::assertSame(1, Version::query()->where('article_id', $uniform->getKey())->count());
    }

    public function test_seed_command_loads_playbooks_and_handles_a_missing_guide(): void
    {
        $guide = resource_path('ai/knowledge/mbfd-support-services-guidance.md');

        Artisan::call('ai-automation:seed');
        $output = Artisan::output();

        self::assertStringContainsString('Playbooks added:', $output);
        self::assertSame(is_file($guide), AiKnowledgeArticle::query()->count() > 0);
        if (! is_file($guide)) {
            self::assertStringContainsString('Starter guide not found', $output);
        }

        Artisan::call('ai-automation:seed');
        self::assertStringContainsString('Playbooks added: 0', Artisan::output());
    }

    public function test_draft_review_publish_supersede(): void
    {
        $editor = $this->user();
        $article = $this->library->createArticle($this->data(['slug' => 'ladder-storage', 'title' => 'Ladder storage', 'body' => 'Ladders are stored on the rear wall rack.', 'topic_tags' => ['ladder storage']]), $editor);

        $v1 = Version::query()->where('article_id', $article->getKey())->sole();
        self::assertSame(Version::STATUS_DRAFT, $v1->status);
        self::assertNull($article->published_version_id);
        self::assertSame([], $this->library->retrieve('where are ladders stored on the rack'));

        $this->library->submitForReview($v1, $editor);
        self::assertSame(Version::STATUS_IN_REVIEW, $v1->fresh()->status);
        self::assertNotNull($v1->fresh()->submitted_at);
        self::assertSame([], $this->library->retrieve('where are ladders stored on the rack'));

        $publisher = $this->user('Pub Lisher');
        $this->library->publish($v1, $publisher);
        $v1 = $v1->fresh();
        self::assertSame(Version::STATUS_PUBLISHED, $v1->status);
        self::assertSame($v1->getKey(), $article->fresh()->published_version_id);
        self::assertSame($publisher->getKey(), $v1->published_by_user_id);
        self::assertSame('Pub Lisher', $v1->published_by_name);
        self::assertNotNull($v1->published_at);

        $v2 = $this->library->saveDraft($article->fresh(), $this->data(['title' => 'Ladder storage', 'body' => 'Ladders moved to the side wall rack.']), $editor);
        self::assertSame(2, $v2->version);
        self::assertSame(Version::STATUS_DRAFT, $v2->status);
        self::assertSame($v1->getKey(), $article->fresh()->published_version_id, 'a draft never changes what is live');

        $this->library->publish($v2, $publisher);
        self::assertSame(Version::STATUS_SUPERSEDED, $v1->fresh()->status);
        self::assertSame($v2->getKey(), $article->fresh()->published_version_id);
    }

    public function test_save_draft_edits_the_open_draft_instead_of_adding_versions(): void
    {
        $editor = $this->user();
        $article = $this->library->createArticle($this->data(['slug' => 'a-b']), $editor);

        $first = $this->library->saveDraft($article, $this->data(['body' => 'Second wording of the guidance.']), $editor);
        $second = $this->library->saveDraft($article, $this->data(['body' => 'Third wording of the guidance.']), $editor);

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(1, Version::query()->where('article_id', $article->getKey())->count());
        self::assertSame('Third wording of the guidance.', $second->fresh()->body);
    }

    public function test_only_a_draft_or_review_version_can_be_published_or_submitted(): void
    {
        $editor = $this->user();
        $article = $this->library->createArticle($this->data(['slug' => 'once']), $editor);
        $version = Version::query()->where('article_id', $article->getKey())->sole();
        $this->library->publish($version, $editor);

        foreach ([fn () => $this->library->publish($version, $editor), fn () => $this->library->submitForReview($version, $editor)] as $attempt) {
            try {
                $attempt();
                self::fail('A published version was moved again.');
            } catch (InvalidArgumentException) {
                self::assertSame(Version::STATUS_PUBLISHED, $version->fresh()->status);
            }
        }
    }

    public function test_publishing_with_a_review_date_in_the_past_is_refused(): void
    {
        $editor = $this->user();
        $article = $this->library->createArticle($this->data(['slug' => 'late', 'review_date' => '2020-01-01']), $editor);

        $this->expectException(InvalidArgumentException::class);
        $this->library->publish(Version::query()->where('article_id', $article->getKey())->sole(), $editor);
    }

    public function test_frozen_versions_cannot_be_edited_or_deleted(): void
    {
        $editor = $this->user();
        $article = $this->library->createArticle($this->data(['slug' => 'frozen']), $editor);
        $version = Version::query()->where('article_id', $article->getKey())->sole();
        $this->library->publish($version, $editor);

        $live = Version::query()->findOrFail($version->getKey());
        try {
            $live->update(['body' => 'Sneaky change.']);
            self::fail('A published version was edited.');
        } catch (LogicException) {
            self::assertStringNotContainsString('Sneaky', $live->fresh()->body);
        }
        try {
            $live->delete();
            self::fail('A published version was deleted.');
        } catch (LogicException) {
            self::assertNotNull($live->fresh());
        }

        $this->library->withdraw($article->fresh(), $editor);
        $withdrawn = Version::query()->findOrFail($version->getKey());
        self::assertSame(Version::STATUS_WITHDRAWN, $withdrawn->status);
        $this->expectException(LogicException::class);
        $withdrawn->update(['status' => Version::STATUS_PUBLISHED]);
    }

    public function test_withdraw_removes_the_article_from_retrieval_immediately(): void
    {
        $editor = $this->user();
        $this->library->importSeed(self::FIXTURE);
        $question = 'Where do I drop off my bunker gear?';

        $found = $this->library->retrieve($question);
        self::assertCount(1, $found);
        self::assertSame('bunker-gear-drop-off', $found[0]->article->slug);

        $article = AiKnowledgeArticle::query()->where('slug', 'bunker-gear-drop-off')->sole();
        self::assertTrue($this->library->withdraw($article, $editor));

        self::assertSame([], $this->library->retrieve($question));
        self::assertNull($article->fresh()->published_version_id);
        self::assertFalse($this->library->withdraw($article->fresh(), $editor), 'a second withdraw is a no-op');
    }

    public function test_an_edit_and_republish_changes_the_answer_without_a_deploy(): void
    {
        $editor = $this->user();
        $this->library->importSeed(self::FIXTURE);
        $article = AiKnowledgeArticle::query()->where('slug', 'bunker-gear-drop-off')->sole();
        $question = 'Where do I drop off my bunker gear?';
        $oldId = $article->published_version_id;

        $draft = $this->library->saveDraft($article, $this->data([
            'title' => 'Bunker gear drop-off',
            'body' => 'Drop bunker gear at the new Station 4 locker room.',
            'topic_tags' => ['bunker gear', 'drop-off'],
        ]), $editor);
        self::assertSame($oldId, $this->library->retrieve($question)[0]->getKey(), 'the old text stays live until publish');

        $this->library->publish($draft, $editor);

        $found = $this->library->retrieve($question);
        self::assertCount(1, $found);
        self::assertSame($draft->getKey(), $found[0]->getKey());
        self::assertStringContainsString('Station 4 locker room', $found[0]->body);
        self::assertNotContains($oldId, array_map(static fn (Version $v): int => $v->getKey(), $found));
    }

    public function test_stale_not_yet_effective_and_not_member_safe_articles_are_excluded(): void
    {
        $editor = $this->user();
        $future = now()->addDays(10)->toDateString();
        $soon = now()->addDays(30)->toDateString();
        $question = 'Where do I drop off my bunker gear?';

        $article = $this->library->createArticle($this->data(['slug' => 'dated', 'title' => 'Bunker gear drop-off', 'body' => 'Drop bunker gear at the bay.', 'topic_tags' => ['bunker gear'], 'review_date' => $soon, 'effective_date' => $future]), $editor);
        $this->library->publish(Version::query()->where('article_id', $article->getKey())->sole(), $editor);
        self::assertSame([], $this->library->retrieve($question), 'not effective yet');

        $this->travelTo(now()->addDays(15));
        self::assertCount(1, $this->library->retrieve($question));

        $this->travelTo(now()->addDays(40));
        self::assertSame([], $this->library->retrieve($question), 'past its review date');
        $this->travelBack();

        $this->travelTo(now()->addDays(15));
        $this->library->updateSettings($article->fresh(), ['bunker gear'], false);
        self::assertSame([], $this->library->retrieve($question), 'not member safe');
        $this->library->updateSettings($article->fresh(), ['bunker gear'], true);
        self::assertCount(1, $this->library->retrieve($question));
        $this->travelBack();
    }

    public function test_unrelated_and_empty_text_returns_nothing(): void
    {
        $this->library->importSeed(self::FIXTURE);

        self::assertSame([], $this->library->retrieve('What time does the barbecue start on Saturday?'));
        self::assertSame([], $this->library->retrieve('Can you please help me with this?'));
        self::assertSame([], $this->library->retrieve(''));
        self::assertSame([], $this->library->retrieve('Where do I drop off my bunker gear?', 0));
    }

    public function test_retrieve_ranks_and_limits_results_deterministically(): void
    {
        $this->library->importSeed(self::FIXTURE);
        $editor = $this->user();
        $article = $this->library->createArticle($this->data(['slug' => 'gear-general', 'title' => 'Gear overview', 'body' => 'General notes about gear and drop-off times.', 'topic_tags' => ['gear']]), $editor);
        $this->library->publish(Version::query()->where('article_id', $article->getKey())->sole(), $editor);

        $found = $this->library->retrieve('Where do I drop off my bunker gear?', 2);
        self::assertSame(['bunker-gear-drop-off', 'gear-general'], array_map(static fn (Version $v): string => $v->article->slug, $found));
        self::assertCount(1, $this->library->retrieve('Where do I drop off my bunker gear?', 1));
        self::assertSame(
            array_map(static fn (Version $v): int => $v->getKey(), $found),
            array_map(static fn (Version $v): int => $v->getKey(), $this->library->retrieve('Where do I drop off my bunker gear?', 2)),
        );
    }

    public function test_upload_validation(): void
    {
        self::assertSame("# Heading\n\nBody text.", $this->library->bodyFromUpload(UploadedFile::fake()->createWithContent('guide.md', "\xEF\xBB\xBF# Heading\r\n\r\nBody text.\r\n")));
        self::assertSame('Plain.', $this->library->bodyFromUpload(UploadedFile::fake()->createWithContent('notes.TXT', 'Plain.')));

        $bad = [
            'php file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
            'double extension' => UploadedFile::fake()->createWithContent('shell.md.php', 'text'),
            'pdf' => UploadedFile::fake()->create('guide.pdf', 10, 'application/pdf'),
            'binary in md' => UploadedFile::fake()->createWithContent('guide.md', "abc\0def"),
            'invalid utf8' => UploadedFile::fake()->createWithContent('guide.txt', "\xff\xfe\xfa\xfb"),
            'too large' => UploadedFile::fake()->create('big.md', 201),
            'empty' => UploadedFile::fake()->createWithContent('empty.md', "  \n "),
        ];
        foreach ($bad as $label => $file) {
            try {
                $this->library->bodyFromUpload($file);
                self::fail("{$label} was accepted.");
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage(), $label);
            }
        }
        self::assertSame(0, AiKnowledgeArticle::query()->count(), 'an upload never stores anything');
    }

    public function test_validation_of_slug_title_and_body(): void
    {
        $editor = $this->user();
        $this->library->createArticle($this->data(['slug' => 'taken']), $editor);

        foreach ([['slug' => 'taken'], ['slug' => 'Bad Slug'], ['slug' => 'x', 'title' => ''], ['slug' => 'y', 'body' => '   ']] as $override) {
            try {
                $this->library->createArticle($this->data($override), $editor);
                self::fail('Invalid article was created.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, AiKnowledgeArticle::query()->count());
    }

    public function test_knowledge_screens_require_the_capability(): void
    {
        $this->library->importSeed(self::FIXTURE);
        $article = AiKnowledgeArticle::query()->where('slug', 'bunker-gear-drop-off')->sole();
        $paths = ['/admin/ai-knowledge', '/admin/ai-knowledge/create', '/admin/ai-knowledge/'.$article->getKey().'/edit'];

        foreach ([$this->user(super: true), $this->user(permissions: ['admin.access', 'admin.ai_automation.manage'])] as $allowed) {
            $this->actingAs($allowed);
            self::assertTrue(AiKnowledgeArticleResource::canViewAny());
            $this->get($paths[0])->assertOk()->assertSee('Knowledge Library');
            Livewire::test(ListAiKnowledgeArticles::class)->loadTable()->assertCanSeeTableRecords([$article])->assertSee('Bunker gear drop-off')
                ->searchTable('bunker')->assertCanSeeTableRecords([$article])
                ->searchTable('zzzz-nothing')->assertCanNotSeeTableRecords([$article])
                ->searchTable('turnout')->assertCanSeeTableRecords([$article]);
            $this->get($paths[1])->assertOk();
            $this->get($paths[2])->assertOk()->assertSee('Internal source note (never shown to members)');
        }

        foreach ([['admin.access'], ['admin.ai_automation.manage'], []] as $permissions) {
            $this->actingAs($this->user(permissions: $permissions));
            self::assertFalse(AiKnowledgeArticleResource::canViewAny(), implode(',', $permissions));
            foreach ($paths as $path) {
                self::assertNotSame(200, $this->get($path)->getStatusCode(), $path.' '.implode(',', $permissions));
            }
        }
    }

    public function test_role_inherited_capability_opens_the_screens_too(): void
    {
        $role = Role::findOrCreate('ai-response-stewards', 'web');
        $role->givePermissionTo(Permission::findOrCreate('admin.ai_automation.manage', 'web'));
        $user = $this->user(permissions: ['admin.access']);
        $user->assignRole($role);
        $this->actingAs($user);

        $this->get('/admin/ai-knowledge')->assertOk();
    }

    public function test_the_screens_expose_no_model_controls(): void
    {
        $this->actingAs($this->user(super: true));

        $html = $this->get('/admin/ai-knowledge/create')->assertOk()->getContent();

        foreach (['temperature', 'max_tokens', 'top_p', 'model_name'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, (string) $html);
        }
    }

    public function test_an_administrator_can_write_review_publish_and_withdraw_on_the_screens(): void
    {
        $this->actingAs($admin = $this->user(super: true));

        Livewire::test(CreateAiKnowledgeArticle::class)
            ->fillForm([
                'title' => 'Key fob requests',
                'slug' => 'key-fob-requests',
                'topic_tags' => ['key fob'],
                'member_safe' => true,
                'body' => 'Ask the station officer to request a new key fob.',
                'source_note' => 'Facilities memo 2026-03',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = AiKnowledgeArticle::query()->where('slug', 'key-fob-requests')->sole();
        self::assertNull($article->published_version_id);

        $edit = Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getKey()])
            ->assertFormSet(['title' => 'Key fob requests', 'body' => 'Ask the station officer to request a new key fob.', 'slug' => 'key-fob-requests'])
            ->callAction('submitForReview');
        self::assertSame(Version::STATUS_IN_REVIEW, Version::query()->where('article_id', $article->getKey())->sole()->status);

        $edit->callAction('publish');
        $article->refresh();
        self::assertNotNull($article->published_version_id);
        self::assertSame($admin->getKey(), Version::query()->findOrFail($article->published_version_id)->published_by_user_id);
        self::assertCount(1, $this->library->retrieve('How do I request a new key fob?'));

        $edit->fillForm(['body' => 'Submit the key fob form to Support Services.', 'change_summary' => 'New process'])->call('save')->assertHasNoFormErrors();
        self::assertSame(2, $article->versions()->count());
        self::assertSame('Ask the station officer to request a new key fob.', Version::query()->findOrFail($article->fresh()->published_version_id)->body, 'saving a draft leaves the live text alone');

        $edit->callAction('publish');
        self::assertStringContainsString('Support Services', $this->library->retrieve('How do I request a new key fob?')[0]->body);

        $edit->callAction('withdraw');
        self::assertNull($article->fresh()->published_version_id);
        self::assertSame([], $this->library->retrieve('How do I request a new key fob?'));
    }

    public function test_saving_an_unchanged_published_article_does_not_start_a_draft(): void
    {
        $this->actingAs($this->user(super: true));
        $this->library->importSeed(self::FIXTURE);
        $article = AiKnowledgeArticle::query()->where('slug', 'bunker-gear-drop-off')->sole();

        Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getKey()])->call('save');

        self::assertSame(1, $article->versions()->count());
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function data(array $override = []): array
    {
        return $override + [
            'slug' => 'sample-article',
            'title' => 'Sample article',
            'body' => 'Sample guidance text for members.',
            'topic_tags' => ['sample'],
            'member_safe' => true,
            'change_summary' => 'Test',
            'source_note' => 'Test note',
            'effective_date' => null,
            'review_date' => null,
        ];
    }

    /** @param list<string> $permissions */
    private function user(string $name = 'Pat Example', bool $super = false, array $permissions = []): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => $name, 'display_name' => $name]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        if ($super) {
            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        }

        return $user;
    }
}

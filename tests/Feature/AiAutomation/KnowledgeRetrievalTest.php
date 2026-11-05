<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Retrieval beyond word overlap: synonyms, catalog aliases, strong matches, previews and self-checks. */
final class KnowledgeRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = new KnowledgeLibrary;
    }

    public function test_a_synonym_in_the_question_finds_the_article_that_uses_the_other_word(): void
    {
        $this->publish('gas-meters', 'Gas meter calibration and swaps', 'The Air Tech checks the gas meter each shift. A dead gas meter is swapped for a spare after you tell the officer.', ['gas meter']);

        // "multigas" is not in the article; the synonym group links it to "gas meter".
        $found = $this->library->retrieve('My multigas is dead, who swaps it?');

        $this->assertSame('gas-meters', $found[0]->article->slug ?? null);
        $preview = $this->library->previewRetrieval('My multigas is dead, who swaps it?');
        $this->assertContains('gas', $preview[0]['synonym_words']);
    }

    public function test_equipment_catalog_aliases_become_synonyms(): void
    {
        $this->publish('thermal-camera', 'Thermal imaging camera care', 'Keep the thermal camera charged and store it in its case between calls.', ['thermal camera']);
        $this->assertSame([], $this->library->retrieve('Where does the TIC go after a call?'), 'no alias yet');

        DB::table('support_equipment_rules')->insert([
            'name' => 'Thermal Imaging Camera', 'normalized_name' => 'thermal imaging camera', 'aliases' => json_encode(['TIC', 'thermal camera']),
            'category' => 'electronics', 'level' => 'medium', 'handler' => 'support_services', 'source' => 'seeded', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $found = (new KnowledgeLibrary)->retrieve('Where does the TIC go after a call?');
        $this->assertSame('thermal-camera', $found[0]->article->slug ?? null);
    }

    public function test_unrelated_text_and_common_single_words_do_not_retrieve(): void
    {
        $this->publish('weekend', 'Station 2 and weekend handoff', 'No admin staff on Saturday and Sunday. Contact 300 if it cannot wait.', ['weekend', 'saturday', 'sunday', 'station 2']);

        $this->assertSame([], $this->library->retrieve('What time is the softball game on Saturday?'));
        $this->assertSame([], $this->library->retrieve('Can you help me with this?'));
    }

    public function test_strong_retrieval_needs_a_phrase_or_several_words(): void
    {
        $this->publish('loans', 'Borrowing equipment for public education', 'Equipment is lent only for public education demonstrations. Ask ahead and wait for a yes.', ['borrow equipment', 'public education']);

        $this->assertNotSame([], $this->library->retrieveStrong('We want to borrow equipment for a school event'));
        $this->assertSame([], $this->library->retrieveStrong('The equipment room door sticks'), 'one shared word is not strong');
    }

    public function test_preview_explains_why_an_article_matched(): void
    {
        $this->publish('consumables', 'Missing kitty litter and other consumables', 'Mark kitty litter Missing on the checkout.', ['kitty litter', 'missing']);

        $preview = $this->library->previewRetrieval('We are missing kitty litter on the truck');

        $this->assertSame('consumables', $preview[0]['slug']);
        $this->assertTrue($preview[0]['retrieved']);
        $this->assertTrue($preview[0]['strong']);
        $this->assertContains('kitty', $preview[0]['matched_words']);
        $this->assertContains('kitty litter', $preview[0]['phrases']);
        $this->assertNull($preview[0]['fts_rank'], 'full-text ranking is PostgreSQL only; SQLite uses the word scorer');
    }

    public function test_sample_phrases_are_stored_and_help_retrieval(): void
    {
        $editor = User::factory()->create();
        $article = $this->library->createArticle([
            'slug' => 'ac-filters', 'title' => 'Air conditioner filters', 'body' => 'Filters that need replacing go in a Station Request.',
            'topic_tags' => ['filters'], 'sample_phrases' => "the ac is blowing warm air\nAC filter is clogged\n\n  ",
        ], $editor);
        $version = Version::query()->where('article_id', $article->getKey())->sole();

        $this->assertSame(['the ac is blowing warm air', 'AC filter is clogged'], json_decode((string) $version->getRawOriginal('sample_phrases'), true));

        $this->library->publish($version, $editor);
        $this->assertNotSame([], $this->library->retrieve('the ac is blowing warm air in the bunk room'));
    }

    public function test_self_retrieval_check_reports_phrases_that_miss_or_lose_to_another_article(): void
    {
        $editor = User::factory()->create();
        $this->publish('consumables', 'Missing kitty litter and other consumables', 'Mark kitty litter Missing on the checkout and check the station for extra first.', ['kitty litter', 'missing', 'checkout']);
        $article = $this->library->createArticle([
            'slug' => 'loans', 'title' => 'Borrowing equipment for public education', 'body' => 'Equipment is lent only for public education demonstrations.',
            'topic_tags' => ['borrow equipment'],
            'sample_phrases' => ['can I borrow a hose for a school visit', 'please help', 'missing kitty litter on the checkout'],
        ], $editor);
        $version = Version::query()->where('article_id', $article->getKey())->sole();

        $report = collect($this->library->selfRetrievalCheck($version))->keyBy('phrase');

        $this->assertTrue($report['can I borrow a hose for a school visit']['retrieved']);
        $this->assertTrue($report['can I borrow a hose for a school visit']['top']);
        $this->assertFalse($report['please help']['retrieved'], 'nothing in it can match');
        $this->assertNull($report['please help']['competitor']);
        $this->assertTrue($report['Borrowing equipment for public education']['top']);
        $this->assertTrue($report['borrow equipment']['top']);
        $this->assertFalse($report['missing kitty litter on the checkout']['top'], 'the consumables article answers this better');
        $this->assertSame('consumables', $report['missing kitty litter on the checkout']['competitor']);
    }

    public function test_every_starter_article_retrieves_for_its_own_phrases(): void
    {
        $this->library->importSeed(resource_path('ai/knowledge/mbfd-support-services-guidance.md'));

        $problems = [];
        foreach (Version::query()->with('article')->where('status', 'published')->get() as $version) {
            foreach ($this->library->selfRetrievalCheck($version) as $row) {
                if (! $row['retrieved']) {
                    $problems[] = "{$version->article->slug}: \"{$row['phrase']}\" does not retrieve its own article";
                } elseif ($row['source'] !== 'tag' && $row['competitor'] !== null) {
                    $problems[] = "{$version->article->slug}: \"{$row['phrase']}\" is answered better by {$row['competitor']}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /** @param  list<string>  $tags */
    private function publish(string $slug, string $title, string $body, array $tags): void
    {
        $article = AiKnowledgeArticle::query()->create(['slug' => $slug, 'title' => $title, 'topic_tags' => $tags, 'member_safe' => true]);
        $version = Version::query()->create([
            'article_id' => $article->id, 'version' => 1, 'title' => $title, 'body' => $body,
            'status' => 'published', 'created_by_name' => 'Test', 'published_at' => now(),
        ]);
        $article->forceFill(['published_version_id' => $version->id])->save();
    }
}

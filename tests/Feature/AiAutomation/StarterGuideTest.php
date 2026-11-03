<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\AiAutomation\AiKnowledgeArticleVersion;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The repository starter guide is member-safe content; these checks keep it that way when it is edited. */
final class StarterGuideTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_SLUGS = [
        'acknowledgment-and-tone', 'missing-low-value-consumables', 'missing-dewalt-batteries-and-shared-tools',
        'equipment-expensive-critical-or-damaged', 'repeat-issue-and-next-checkout', 'station-request-and-facilities',
        'bunker-gear-drop-off', 'hub-digital-workflow-crosswalk', 'station2-and-weekend-handoff',
        'contact-and-routing-roles', 'when-to-escalate',
    ];

    public function test_seed_imports_every_article_once_and_never_overwrites_edits(): void
    {
        Artisan::call('ai-automation:seed');

        $slugs = AiKnowledgeArticleVersion::query()->with('article')->get()->pluck('article.slug')->all();
        foreach (self::REQUIRED_SLUGS as $slug) {
            $this->assertContains($slug, $slugs);
        }
        $this->assertGreaterThan(0, AiAutomationPlaybook::query()->count());

        AiAutomationPlaybook::query()->where('key', 'receipt')->update(['body' => 'Got it.']);
        $count = AiKnowledgeArticleVersion::query()->count();
        Artisan::call('ai-automation:seed');

        $this->assertSame($count, AiKnowledgeArticleVersion::query()->count());
        $this->assertSame('Got it.', AiAutomationPlaybook::query()->where('key', 'receipt')->value('body'));
    }

    public function test_published_starter_articles_contain_nothing_a_member_must_not_see(): void
    {
        Artisan::call('ai-automation:seed');

        foreach (AiKnowledgeArticleVersion::query()->where('status', 'published')->get() as $version) {
            $text = $version->title."\n".$version->body;
            $this->assertDoesNotMatchRegularExpression('/\bSOGs?\b|Section\s*800|800\.P\d|\bmanual\b/i', $text, "{$version->title} names a document");
            $this->assertDoesNotMatchRegularExpression('/\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}/', $text, "{$version->title} has a phone number");
            $this->assertDoesNotMatchRegularExpression('/@[a-z0-9.-]+\.[a-z]{2,}|https?:\/\//i', $text, "{$version->title} has an email or link");
            $this->assertDoesNotMatchRegularExpression('/48[- ]hour|within \d+ (?:hours|days)/i', $text, "{$version->title} promises a turnaround");
        }
    }

    public function test_the_guide_answers_a_routine_question_and_ignores_unrelated_ones(): void
    {
        Artisan::call('ai-automation:seed');
        $library = app(KnowledgeLibrary::class);

        $this->assertNotSame([], $library->retrieve('Where do I get more kitty litter if the station is out?'));
        $this->assertSame([], $library->retrieve('What time is the softball game on Saturday?'));
    }
}

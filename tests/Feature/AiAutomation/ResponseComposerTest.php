<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion;
use App\Services\AiAutomation\ComposedReply;
use App\Services\AiAutomation\Finding;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ResponseComposer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The owner's reply playbook, case by case. Every case here uses zero model calls unless it says so. */
final class ResponseComposerTest extends TestCase
{
    use RefreshDatabase;

    private const TUESDAY = '2026-11-03 15:00:00';

    private const SATURDAY = '2026-11-07 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_plain_statement_gets_acknowledged_only(): void
    {
        $reply = $this->message('support_message', 'The bay door on the left side is sticking again.');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertSame(ComposedReply::RECEIPT_ONLY, $reply->decision);
    }

    public function test_missing_kitty_litter_gets_the_station_check_and_station_2_line(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing')]);

        $this->assertSame("Please check if there is any extra kitty litter at your station. If it's still missing, call or text before coming by Station 2 so we can check on a replacement.", $reply->text);
        $this->assertSame('missing_consumable', $reply->templateKey);
        $this->assertSame(ComposedReply::RECEIPT_PLUS_GUIDANCE, $reply->decision);
    }

    public function test_missing_dewalt_battery_gets_the_blower_reminder(): void
    {
        $reply = $this->findings([new Finding('DeWalt 20V Battery', 'missing')]);

        $this->assertStringContainsString('such as the station blower', $reply->text);
        $this->assertSame('missing_dewalt_battery', $reply->templateKey);
    }

    public function test_other_tool_batteries_do_not_mention_the_blower(): void
    {
        $reply = $this->findings([new Finding('Milwaukee Battery', 'missing')]);

        $this->assertStringNotContainsString('blower', $reply->text);
        $this->assertSame('missing_tool_battery', $reply->templateKey);
    }

    public function test_missing_routine_interchangeable_equipment(): void
    {
        $reply = $this->findings([new Finding('Spanner Wrench', 'missing')]);

        $this->assertStringStartsWith('Please check whether the item was moved to another compartment', $reply->text);
    }

    public function test_several_routine_missing_items_get_one_combined_reply(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Spanner Wrench', 'missing')]);

        $this->assertSame('missing_multiple', $reply->templateKey);
        $this->assertStringContainsString('replacements', $reply->text);
    }

    /** @return array<string, array{0: Finding}> */
    public static function criticalFindings(): array
    {
        return [
            'scba' => [new Finding('SCBA Cylinder', 'missing')],
            'radio' => [new Finding('Portable Radio', 'missing')],
            'lifepak' => [new Finding('LifePak 15', 'damaged')],
            'tires' => [new Finding('Front Tire', 'damaged')],
            'significant damage' => [new Finding('Spanner Wrench', 'damaged', true)],
            'gas monitor' => [new Finding('Gas Monitor', 'missing')],
        ];
    }

    /** @dataProvider criticalFindings */
    public function test_critical_or_significant_findings_get_acknowledged_only_and_flagged(Finding $finding): void
    {
        $reply = $this->findings([$finding]);

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertSame(ComposedReply::RECEIPT_AND_HUMAN_FLAG, $reply->decision);
        $this->assertSame('critical', $reply->riskTier);
    }

    public function test_a_critical_item_anywhere_in_the_checkout_suppresses_all_guidance(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('SCBA Mask', 'missing')]);

        $this->assertSame('Acknowledged.', $reply->text);
    }

    public function test_expensive_items_get_acknowledged_only(): void
    {
        $reply = $this->findings([new Finding('iPad', 'missing')]);

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertSame('elevated', $reply->riskTier);
    }

    public function test_unknown_items_get_no_guessed_guidance(): void
    {
        $reply = $this->findings([new Finding('Thing We Have Never Seen', 'missing')]);

        $this->assertSame('Acknowledged.', $reply->text);
    }

    public function test_damaged_noncritical_item(): void
    {
        $reply = $this->findings([new Finding('Broom', 'damaged')]);

        $this->assertSame("Acknowledged. We'll follow up if needed.", $reply->text);
    }

    public function test_weekend_line_only_on_saturday_or_sunday_when_needed_now(): void
    {
        $urgent = [new Finding('Kitty Litter', 'missing', false, true, 'Need it ASAP')];

        $saturday = $this->findings($urgent, self::SATURDAY);
        $this->assertStringEndsWith('If it needs to be addressed immediately this weekend, contact 300 to check whether a spare is available.', $saturday->text);

        $this->assertStringNotContainsString('300', $this->findings($urgent, self::TUESDAY)->text);
        $this->assertStringNotContainsString('300', $this->findings([new Finding('Kitty Litter', 'missing')], self::SATURDAY)->text);
    }

    public function test_weekend_is_judged_in_eastern_time(): void
    {
        // 2026-11-09 02:00 UTC is still Sunday evening in Miami Beach.
        $reply = $this->findings([new Finding('Kitty Litter', 'missing', false, true, 'need it today')], '2026-11-09 02:00:00');

        $this->assertStringContainsString('contact 300', $reply->text);
    }

    public function test_station_request_says_forwarded_only_when_it_really_was(): void
    {
        $composer = app(ResponseComposer::class);
        $now = CarbonImmutable::parse(self::TUESDAY);

        $routed = $composer->compose(new IntakeFacts('station', 'station_request', null, [], 'Washer is leaking water on the floor.', 'appliance', false, null, true), $now);
        $this->assertSame('Acknowledged.', $routed->text, 'leak is a hazard word: receipt only');

        $routed = $composer->compose(new IntakeFacts('station', 'station_request', null, [], 'Need two new chairs for the kitchen.', 'equipment', false, null, true), $now);
        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel.', $routed->text);

        $unrouted = $composer->compose(new IntakeFacts('station', 'station_request', null, [], 'Need two new chairs for the kitchen.', 'equipment'), $now);
        $this->assertSame('Acknowledged.', $unrouted->text);
    }

    public function test_bunker_gear_drop_off_is_acknowledged_only(): void
    {
        $reply = $this->message('support_message', 'Dropped off my bunker gear at Station 2 for cleaning.');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertContains('bunker_gear_drop_off', $reply->reasonCodes);
    }

    public function test_bunker_gear_with_exposure_or_damage_stays_flagged(): void
    {
        $reply = $this->message('support_message', 'Left my bunker gear in the gear bin, contaminated at the fire last night.');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertSame('critical', $reply->riskTier);
    }

    public function test_a_generic_left_my_message_is_not_treated_as_a_gear_drop_off(): void
    {
        $reply = $this->message('support_message', 'Left my radio at the shop.');

        $this->assertSame('critical', $reply->riskTier);
        $this->assertNotContains('bunker_gear_drop_off', $reply->reasonCodes);
    }

    public function test_unit_and_station_names_are_not_safety_words(): void
    {
        $composer = app(ResponseComposer::class);
        $facts = new IntakeFacts('station', 'station_request', null, [], 'Engine 2 crew needs two new chairs at Fire Station 4.', 'equipment', false, null, true);

        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel.', $composer->compose($facts, CarbonImmutable::parse(self::TUESDAY))->text);
    }

    public function test_plurals_match_the_word_lists(): void
    {
        $this->assertSame('missing_interchangeable', $this->findings([new Finding('Spanners', 'missing')])->templateKey);
        $this->assertSame('elevated', $this->findings([new Finding('Elevator Keys', 'missing')])->riskTier);
        $this->assertSame('critical', $this->message('station_request', 'Water leaking from the ceiling')->riskTier);
    }

    public function test_dewalt_saws_are_escalated_not_given_the_battery_reminder(): void
    {
        $reply = $this->findings([new Finding('Dewalt K-Saw with Spare battery', 'missing')]);

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertSame('critical', $reply->riskTier);
    }

    public function test_a_question_with_no_published_guidance_gets_acknowledged(): void
    {
        $reply = $this->message('support_message', 'How do I get a new locker key?');

        $this->assertSame('Acknowledged.', $reply->text);
    }

    public function test_a_question_with_published_guidance_but_no_model_gets_acknowledged(): void
    {
        $this->publishArticle();

        $reply = $this->message('support_message', 'Where do I report missing kitty litter on the truck?');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertContains('question_model_unavailable', $reply->reasonCodes);
    }

    public function test_a_question_with_published_guidance_uses_the_model_and_validates_the_answer(): void
    {
        $this->publishArticle();
        $this->enableModel();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->modelResponse("Mark it Missing on the daily checkout, then check the station for extra. If there's none, call or text before coming by Station 2."))]);

        $reply = $this->message('support_message', 'Where do I report missing kitty litter on the truck?');

        $this->assertSame(ComposedReply::APPROVED_RAG_GUIDANCE, $reply->decision);
        $this->assertStringStartsWith('Mark it Missing', $reply->text);
        $this->assertNotEmpty($reply->knowledgeRefs);
        $this->assertNotNull($reply->modelCallId);
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedModelAnswers(): array
    {
        return [
            'pleasantry' => ['Thanks for reaching out! Mark it Missing on the checkout.'],
            'promise' => ["We have ordered a replacement and it's on its way."],
            'cites a document' => ['Per the Support Services SOG, mark it Missing on the checkout.'],
            'phone number' => ['Call 305-555-0100 before coming by.'],
            'link' => ['See https://example.com for the steps.'],
            'too long' => [str_repeat('Check the station first. ', 20)],
            'no answer' => ['NO_ANSWER'],
            'not grounded in the article' => ['Go see the chief about it when you get a chance.'],
        ];
    }

    /** @dataProvider rejectedModelAnswers */
    public function test_unsafe_model_answers_fall_back_to_acknowledged(string $answer): void
    {
        $this->publishArticle();
        $this->enableModel();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->modelResponse($answer))]);

        $reply = $this->message('support_message', 'Where do I report missing kitty litter on the truck?');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertContains('model_answer_rejected', $reply->reasonCodes);
    }

    public function test_safety_questions_never_reach_the_model(): void
    {
        $this->publishArticle();
        $this->enableModel();
        Http::fake();

        $reply = $this->message('support_message', 'How do I refill the SCBA bottle at the station?');

        $this->assertSame('Acknowledged.', $reply->text);
        Http::assertNothingSent();
    }

    public function test_backlog_composition_never_calls_the_model(): void
    {
        $this->publishArticle();
        $this->enableModel();
        Http::fake();

        $reply = app(ResponseComposer::class)->compose(
            new IntakeFacts('support', 'support_message', null, [], 'Where do I report missing kitty litter on the truck?'),
            CarbonImmutable::parse(self::TUESDAY),
            false,
        );

        $this->assertSame('Acknowledged.', $reply->text);
        Http::assertNothingSent();
    }

    public function test_admin_edited_templates_are_used(): void
    {
        app(\App\Services\AiAutomation\PlaybookRepository::class)->ensureSeeded();
        \App\Models\AiAutomation\AiAutomationPlaybook::query()->where('key', 'receipt')->update(['body' => 'Got it.', 'version' => 2]);

        $reply = $this->message('support_message', 'Bay door sticking.');

        $this->assertSame('Got it.', $reply->text);
        $this->assertSame(2, $reply->templateVersion);
    }

    /** @param  list<Finding>  $findings */
    private function findings(array $findings, string $now = self::TUESDAY): ComposedReply
    {
        return app(ResponseComposer::class)->compose(
            new IntakeFacts('vehicle', 'inspection_findings', null, $findings),
            CarbonImmutable::parse($now, 'UTC'),
        );
    }

    private function message(string $kind, string $text): ComposedReply
    {
        return app(ResponseComposer::class)->compose(
            new IntakeFacts('support', $kind, null, [], $text),
            CarbonImmutable::parse(self::TUESDAY),
        );
    }

    private function publishArticle(): void
    {
        $article = AiKnowledgeArticle::query()->create([
            'slug' => 'missing-low-value-consumables',
            'title' => 'Missing kitty litter and other low-cost consumables',
            'topic_tags' => ['missing', 'kitty litter', 'consumable', 'checkout'],
            'member_safe' => true,
        ]);
        $version = AiKnowledgeArticleVersion::query()->create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => $article->title,
            'body' => 'Report missing kitty litter by marking it Missing on the daily checkout. Check the station for extra first. If there is none, call or text before coming by Station 2 so Support Services can check on a replacement.',
            'status' => 'published',
            'created_by_name' => 'Test',
            'published_at' => now(),
        ]);
        $article->forceFill(['published_version_id' => $version->id])->save();
    }

    private function enableModel(): void
    {
        config([
            'ai_automation.model.enabled' => true,
            'ai_automation.model.account_id' => str_repeat('a1', 16),
            'ai_automation.model.api_token' => 'test-token',
        ]);
    }

    /** @return array<string, mixed> */
    private function modelResponse(string $text): array
    {
        return [
            'success' => true,
            'result' => [
                'output' => [
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]],
                ],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 40],
            ],
        ];
    }
}

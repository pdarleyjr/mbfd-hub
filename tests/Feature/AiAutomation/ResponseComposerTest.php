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

    private const SEARCH = "hasn't been misplaced and isn't somewhere around the station, on another apparatus, or left on a spare truck during a recent truck swap.";

    private const CALL_AHEAD = 'call or text us before heading to Station 2 so we can confirm we have a spare ready.';

    private const READINESS = "If this affects your unit's readiness, notify your officer and 300 right away.";

    private const WEEKEND = "Support Services staff are off this weekend. If this can't wait, contact 300. Otherwise we'll follow up on the next weekday.";

    public function test_a_missing_item_gets_the_look_first_reply_naming_the_item(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing')]);

        $this->assertSame('Acknowledged. Please make sure the kitty litter '.self::SEARCH." If it's still missing, ".self::CALL_AHEAD, $reply->text);
        $this->assertSame('missing_item', $reply->templateKey);
        $this->assertSame('routine', $reply->riskTier);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function anyMissingItem(): array
    {
        return [
            'tool' => ['Spanner Wrench', 'spanner wrench'],
            'expensive' => ['iPad', 'ipad'],
            'unknown' => ['Thing We Have Never Seen', 'thing we have never seen'],
            'acronym kept' => ['TIC Mount (rear)', 'TIC mount'],
        ];
    }

    /** @dataProvider anyMissingItem */
    public function test_every_missing_vehicle_item_gets_the_same_reply_with_its_own_name(string $item, string $label): void
    {
        $reply = $this->findings([new Finding($item, 'missing')]);

        $this->assertStringContainsString("make sure the {$label} hasn't been misplaced", $reply->text);
        $this->assertStringContainsString(self::CALL_AHEAD, $reply->text);
    }

    public function test_a_missing_battery_is_reminded_about_chargers_and_other_tools(): void
    {
        $reply = $this->findings([new Finding('DeWalt 20V Battery', 'missing')]);

        $this->assertSame('missing_battery', $reply->templateKey);
        $this->assertStringContainsString("make sure the dewalt 20V battery wasn't left on a charger or in another tool, such as the station blower", $reply->text);
    }

    public function test_several_missing_items_get_one_reply_listing_them(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Spanner Wrench', 'missing'), new Finding('K-Tool', 'missing')]);

        $this->assertSame('missing_items', $reply->templateKey);
        $this->assertStringContainsString("make sure the kitty litter, spanner wrench and k-tool haven't been misplaced", $reply->text);

        $many = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Spanner Wrench', 'missing'), new Finding('Broom', 'missing'), new Finding('Bucket', 'missing')]);
        $this->assertStringContainsString('kitty litter, spanner wrench, broom and other items', $many->text);
    }

    public function test_several_missing_items_with_a_battery_add_the_charger_line(): void
    {
        $reply = $this->findings([new Finding('Spanner Wrench', 'missing'), new Finding('M18 Batteries', 'missing')]);

        $this->assertStringEndsWith('Check chargers and other tools, such as the station blower, for any batteries.', $reply->text);
    }

    /** @return array<string, array{0: Finding}> */
    public static function readinessFindings(): array
    {
        return [
            'scba' => [new Finding('SCBA Cylinder', 'missing')],
            'radio' => [new Finding('Portable Radio', 'missing')],
            'saw with battery' => [new Finding('Dewalt K-Saw with Spare battery', 'missing')],
            'significant' => [new Finding('Spanner Wrench', 'missing', true)],
        ];
    }

    /** @dataProvider readinessFindings */
    public function test_a_missing_readiness_item_also_tells_them_to_notify_the_officer_and_300(Finding $finding): void
    {
        $reply = $this->findings([$finding]);

        $this->assertStringStartsWith('Acknowledged. Please make sure the ', $reply->text);
        $this->assertStringEndsWith(self::READINESS, $reply->text);
        $this->assertSame('critical', $reply->riskTier);
        $this->assertSame(ComposedReply::RECEIPT_AND_HUMAN_FLAG, $reply->decision);
    }

    public function test_damaged_items(): void
    {
        $this->assertSame("Acknowledged. We'll follow up if needed.", $this->findings([new Finding('Broom', 'damaged')])->text);
        $this->assertSame('Acknowledged. '.self::READINESS, $this->findings([new Finding('Front Tire', 'damaged')])->text);
    }

    public function test_a_checkout_with_missing_and_damaged_items_answers_the_missing_ones(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Broom', 'damaged')]);

        $this->assertStringContainsString("make sure the kitty litter hasn't been misplaced", $reply->text);
    }

    public function test_weekends_add_the_300_line_instead_of_the_readiness_line(): void
    {
        $routine = $this->findings([new Finding('Kitty Litter', 'missing')], self::SATURDAY);
        $this->assertStringEndsWith(self::WEEKEND, $routine->text);

        $critical = $this->findings([new Finding('SCBA Cylinder', 'missing')], self::SATURDAY);
        $this->assertStringEndsWith(self::WEEKEND, $critical->text);
        $this->assertStringNotContainsString('notify your officer', $critical->text);

        $this->assertStringNotContainsString('weekend', $this->findings([new Finding('Kitty Litter', 'missing')])->text);
    }

    public function test_weekend_is_judged_in_eastern_time(): void
    {
        // 2026-11-09 02:00 UTC is still Sunday evening in Miami Beach; 2026-11-07 03:00 UTC is still Friday.
        $this->assertStringEndsWith(self::WEEKEND, $this->findings([new Finding('Kitty Litter', 'missing')], '2026-11-09 02:00:00')->text);
        $this->assertStringNotContainsString('weekend', $this->findings([new Finding('Kitty Litter', 'missing')], '2026-11-07 03:00:00')->text);
    }

    public function test_weekend_line_on_station_requests_and_urgent_support_messages(): void
    {
        $composer = app(ResponseComposer::class);
        $saturday = CarbonImmutable::parse(self::SATURDAY);

        $request = $composer->compose(new IntakeFacts('station', 'station_request', null, [], 'Need two new chairs for the kitchen.', 'equipment', false, null, true), $saturday);
        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel. '.self::WEEKEND, $request->text);

        $urgent = $composer->compose(new IntakeFacts('support', 'support_message', null, [], 'Bay door remote stopped working, need it today.'), $saturday);
        $this->assertSame('Acknowledged. '.self::WEEKEND, $urgent->text);

        $calm = $composer->compose(new IntakeFacts('support', 'support_message', null, [], 'Bay door remote is getting weak.'), $saturday);
        $this->assertSame('Acknowledged.', $calm->text);
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
        $this->assertSame('critical', $this->findings([new Finding('Portable Radios', 'missing')])->riskTier);
        $this->assertSame('elevated', $this->message('support_message', 'Lost the elevator keys again')->riskTier);
        $this->assertSame('critical', $this->message('station_request', 'Water leaking from the ceiling')->riskTier);
    }

    public function test_standing_guidance_travels_with_every_model_call(): void
    {
        $this->publishArticle();
        $this->publishArticle('how-support-services-handles-submissions', 'How Support Services handles submissions', ['support-services'],
            'On Saturday and Sunday no Support Services staff are on duty; anything that cannot wait goes to 300.', true);
        $this->enableModel();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->modelResponse('Mark it Missing on the daily checkout, then check the station for extra.'))]);

        $reply = $this->message('support_message', 'Where do I report missing kitty litter on the truck?');

        $this->assertSame(ComposedReply::APPROVED_RAG_GUIDANCE, $reply->decision);
        $this->assertCount(2, $reply->knowledgeRefs);
        Http::assertSent(fn ($request): bool => str_contains((string) json_encode($request->data()), 'no Support Services staff are on duty'));
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

    /** @param  list<string>|null  $tags */
    private function publishArticle(
        string $slug = 'missing-low-value-consumables',
        string $title = 'Missing kitty litter and other low-cost consumables',
        ?array $tags = null,
        ?string $body = null,
        bool $always = false,
    ): void {
        $article = AiKnowledgeArticle::query()->create([
            'slug' => $slug,
            'title' => $title,
            'topic_tags' => $tags ?? ['missing', 'kitty litter', 'consumable', 'checkout'],
            'member_safe' => true,
            'always_include' => $always,
        ]);
        $version = AiKnowledgeArticleVersion::query()->create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => $article->title,
            'body' => $body ?? 'Report missing kitty litter by marking it Missing on the daily checkout. Check the station for extra first. If there is none, call or text before coming by Station 2 so Support Services can check on a replacement.',
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

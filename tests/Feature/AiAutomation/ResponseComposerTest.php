<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion;
use App\Services\AiAutomation\ComposedReply;
use App\Services\AiAutomation\Finding;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ResponseComposer;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AiAutomation\Support\FakeEquipmentClassifier;
use Tests\TestCase;

/** The owner's reply playbook, case by case. Every case here uses zero model calls unless it says so. */
final class ResponseComposerTest extends TestCase
{
    use RefreshDatabase;

    private const TUESDAY = '2026-11-03 15:00:00';

    private const SATURDAY = '2026-11-07 15:00:00';

    private const LOW_MISSING = 'Please check around the station and other apparatus for the %s first. If nothing turns up, call or text before coming by Station 2 so we can check on a replacement.';

    private const LOW_MULTI = "Please check around the station and other apparatus to make sure the %s weren't misplaced. If anything is still missing, call or text before coming by Station 2 so we can check on replacements.";

    private const WEEKEND = "Support Services staff are off this weekend. If this can't wait, contact 300. Otherwise we'll follow up on the next weekday.";

    private const MEDIUM_TOOL = 'Acknowledged. Call or text before coming by Station 2 so we can check whether a replacement is available.';

    private const OFFICER_300 = 'Acknowledged. Notify your officer and 300 right away.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->bind(EquipmentClassifier::class, FakeEquipmentClassifier::class);
    }

    public function test_plain_statement_gets_acknowledged_only(): void
    {
        $reply = $this->message('support_message', 'The bay door on the left side is sticking again.');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertSame(ComposedReply::RECEIPT_ONLY, $reply->decision);
        $this->assertSame('routine', $reply->riskTier);
    }

    public function test_a_low_missing_item_gets_the_look_first_reply_naming_the_item(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing')]);

        $this->assertSame(sprintf(self::LOW_MISSING, 'Kitty Litter'), $reply->text);
        $this->assertSame('missing_low', $reply->templateKey);
        $this->assertSame('routine', $reply->riskTier);
        $this->assertSame(ComposedReply::RECEIPT_PLUS_GUIDANCE, $reply->decision);
    }

    public function test_item_names_are_trimmed_and_never_shout(): void
    {
        $this->assertStringContainsString('for the Spanner Wrench first', $this->findings([new Finding('Spanner Wrench (rear compartment)', 'missing')])->text);
        $this->assertStringContainsString('for the Kitty Litter first', $this->findings([new Finding('KITTY LITTER', 'missing')])->text);
        $this->assertStringContainsString('for the Hydrant Wrench first', $this->findings([new Finding('HYDRANT WRENCH', 'missing')])->text);
    }

    public function test_a_missing_dewalt_battery_is_pointed_at_chargers_and_other_tools(): void
    {
        $reply = $this->findings([new Finding('DeWalt 20V Battery', 'missing')]);

        $this->assertSame("Check the chargers and other DeWalt tools, including the station blower. If it's still missing, call or text before coming by Station 2 so we can check on a replacement.", $reply->text);
        $this->assertSame('missing_battery_dewalt', $reply->templateKey);
    }

    public function test_another_brand_of_battery_gets_the_generic_variant(): void
    {
        $reply = $this->findings([new Finding('M18 Battery', 'missing')]);

        $this->assertSame('missing_battery', $reply->templateKey);
        $this->assertStringStartsWith('Check the chargers and other tools, including the station blower, to make sure the M18 Battery wasn', $reply->text);
    }

    public function test_several_low_items_are_named_in_one_reply(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Spanner Wrench', 'missing'), new Finding('Bucket', 'missing')]);
        $this->assertSame('missing_low_multi', $reply->templateKey);
        $this->assertSame(sprintf(self::LOW_MULTI, 'Kitty Litter, Spanner Wrench and Bucket'), $reply->text);

        $many = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Spanner Wrench', 'missing'), new Finding('Broom', 'missing'), new Finding('Bucket', 'missing')]);
        $this->assertStringContainsString('Kitty Litter, Spanner Wrench, Broom and other items', $many->text);

        $twice = $this->findings([new Finding('Broom', 'missing'), new Finding('broom (cab)', 'missing')]);
        $this->assertSame('missing_low', $twice->templateKey, 'the same item twice is one item');
    }

    public function test_several_low_items_with_a_battery_add_the_charger_line(): void
    {
        $reply = $this->findings([new Finding('Spanner Wrench', 'missing'), new Finding('M18 Batteries', 'missing')]);

        $this->assertStringEndsWith('Check chargers and other tools, such as the station blower, for any batteries.', $reply->text);
    }

    public function test_damaged_low_item_and_medium_item(): void
    {
        $damaged = $this->findings([new Finding('Broom', 'damaged')]);
        $this->assertSame("Acknowledged. We'll follow up if needed.", $damaged->text);
        $this->assertSame(ComposedReply::RECEIPT_ONLY, $damaged->decision);
        $this->assertSame(self::MEDIUM_TOOL, $this->findings([new Finding('Honda Generator', 'damaged')])->text);
    }

    public function test_medium_expensive_tool_adds_the_300_line_only_when_the_report_is_urgent(): void
    {
        $plain = $this->findings([new Finding('iPad', 'missing')]);
        $this->assertSame(self::MEDIUM_TOOL, $plain->text);
        $this->assertSame('routine', $plain->riskTier);

        $urgent = $this->findings([new Finding('iPad', 'missing', false, true, 'need it right away for the drill')]);
        $this->assertSame(self::MEDIUM_TOOL." If it's needed right away, notify your officer and coordinate through 300.", $urgent->text);
    }

    public function test_gas_meter_scba_component_and_captain5_replies(): void
    {
        $this->assertSame('Acknowledged. Coordinate a replacement gas meter or detector with Support Services or the appropriate equipment technician. Call or text first so availability can be confirmed.', $this->findings([new Finding('Four Gas Meter', 'missing')])->text);

        $air = $this->findings([new Finding('SCBA Regulator', 'damaged')]);
        $this->assertSame('Acknowledged. Coordinate with the on-duty Air Tech to have the item checked or swapped out. Notify your officer and contact 300 if immediate assistance or after-hours coordination is needed.', $air->text);
        $this->assertSame('elevated', $air->riskTier);

        $this->assertSame('Acknowledged. Coordinate with Captain 5 to arrange a replacement or pickup. Confirm availability before coming by.', $this->findings([new Finding('Lifepak 15', 'missing')])->text);
        $this->assertSame('Acknowledged. Contact Captain 5 to coordinate a replacement and pickup.', $this->findings([new Finding('Splint set', 'missing')])->text);
    }

    public function test_a_leaking_cylinder_says_to_take_it_out_of_use(): void
    {
        $reply = $this->findings([new Finding('SCBA Cylinder', 'damaged', false, true, 'leaking at the valve')]);

        $this->assertSame('Acknowledged. Remove the leaking cylinder from use and contact the on-duty Air Tech to have it assessed or swapped out. Notify your officer, and coordinate through 300 if immediate or after-hours assistance is needed.', $reply->text);
        $this->assertSame('elevated', $reply->riskTier);
    }

    public function test_high_protective_equipment_and_hazards(): void
    {
        $ppe = $this->findings([new Finding('SCBA Unit', 'missing')]);
        $this->assertSame('Acknowledged. Notify your officer immediately and coordinate with Support Services or 300 for further direction.', $ppe->text);
        $this->assertSame('critical', $ppe->riskTier);
        $this->assertSame(ComposedReply::RECEIPT_AND_HUMAN_FLAG, $ppe->decision);

        $this->assertSame(self::OFFICER_300, $this->findings([new Finding('Spanner Wrench', 'missing', true)])->text, 'a significant flag reads HIGH');
        $this->assertSame(self::OFFICER_300, $this->findings([new Finding('Side Door', 'damaged', false, true, 'sparking when closed')])->text);
    }

    public function test_the_defect_level_staff_see_wins_over_the_catalog(): void
    {
        $this->projectedLevels([501 => 'high', 502 => 'low']);

        $this->assertSame(self::OFFICER_300, $this->findings([new Finding('Kitty Litter', 'missing', false, true, null, null, 501)])->text);
        $this->assertSame(sprintf(self::LOW_MISSING, 'iPad'), $this->findings([new Finding('iPad', 'missing', false, true, null, null, 502)])->text, 'staff lowered it to LOW');
        $this->assertSame(self::MEDIUM_TOOL, $this->findings([new Finding('iPad', 'missing', false, true, null, null, 999)])->text, 'no projection for this defect: the catalog level');
    }

    public function test_mixed_findings_get_one_reply_driven_by_the_highest_level(): void
    {
        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Broom', 'damaged'), new Finding('Honda Generator', 'missing'), new Finding('SCBA Unit', 'damaged')]);
        $this->assertSame('high_ppe', $reply->templateKey);

        $reply = $this->findings([new Finding('Kitty Litter', 'missing'), new Finding('Spanner Wrench', 'missing'), new Finding('iPad', 'missing')]);
        $this->assertSame(self::MEDIUM_TOOL, $reply->text, 'MEDIUM outranks two LOW items');

        $reply = $this->findings([new Finding('Broom', 'damaged'), new Finding('Kitty Litter', 'missing')]);
        $this->assertSame(sprintf(self::LOW_MISSING, 'Kitty Litter'), $reply->text, 'missing items are named before damaged ones');
    }

    public function test_a_catalog_member_response_replaces_the_default_for_that_item(): void
    {
        $reply = $this->findings([new Finding('Special Widget', 'missing')]);

        $this->assertSame('Check the Special Widget cabinet, then call us.', $reply->text);
        $this->assertContains('rule_77', $reply->reasonCodes);
    }

    public function test_weekend_line_only_for_recent_support_services_replies(): void
    {
        $composer = app(ResponseComposer::class);
        $saturday = CarbonImmutable::parse(self::SATURDAY, 'UTC');
        $recent = $saturday->subHours(5);
        $facts = static fn (string $name, ?CarbonImmutable $submitted) => new IntakeFacts('vehicle', 'inspection_findings', null, [new Finding($name, 'missing')], submittedAt: $submitted);

        $low = $composer->compose($facts('Kitty Litter', $recent), $saturday);
        $this->assertSame(sprintf(self::LOW_MISSING, 'Kitty Litter').' '.self::WEEKEND, $low->text);

        $medium = $composer->compose($facts('Honda Generator', $recent), $saturday);
        $this->assertStringEndsWith(self::WEEKEND, $medium->text);

        // Air Tech, Captain 5 and HIGH replies already point at 300: no weekend line.
        foreach (['SCBA Cylinder', 'Lifepak 15', 'SCBA Unit'] as $name) {
            $this->assertStringNotContainsString('this weekend', $composer->compose($facts($name, $recent), $saturday)->text, $name);
        }

        // Old backlog item, or a submission with no timestamp: never.
        $this->assertStringNotContainsString('weekend', $composer->compose($facts('Kitty Litter', $saturday->subDays(6)), $saturday)->text);
        $this->assertStringNotContainsString('weekend', $composer->compose($facts('Kitty Litter', null), $saturday)->text);

        // Weekday: never.
        $tuesday = CarbonImmutable::parse(self::TUESDAY, 'UTC');
        $this->assertStringNotContainsString('weekend', $composer->compose($facts('Kitty Litter', $tuesday->subHour()), $tuesday)->text);
    }

    public function test_weekend_is_judged_in_eastern_time(): void
    {
        $composer = app(ResponseComposer::class);
        $reply = static fn (string $now): string => $composer->compose(
            new IntakeFacts('vehicle', 'inspection_findings', null, [new Finding('Kitty Litter', 'missing')], submittedAt: CarbonImmutable::parse($now, 'UTC')->subHour()),
            CarbonImmutable::parse($now, 'UTC'),
        )->text;

        // 2026-11-09 02:00 UTC is still Sunday evening in Miami Beach; 2026-11-07 03:00 UTC is still Friday.
        $this->assertStringEndsWith(self::WEEKEND, $reply('2026-11-09 02:00:00'));
        $this->assertStringNotContainsString('weekend', $reply('2026-11-07 03:00:00'));
    }

    public function test_station_requests_hazards_forwarding_and_the_weekend_line(): void
    {
        $composer = app(ResponseComposer::class);
        $now = CarbonImmutable::parse(self::TUESDAY);
        $request = static fn (string $text, bool $routed, ?CarbonImmutable $submitted = null) => new IntakeFacts('station', 'station_request', null, [], $text, 'equipment', false, null, $routed, $submitted);

        $this->assertSame('Acknowledged. If there is an immediate hazard, notify your officer and 300 right away.', $composer->compose($request('Outlet is sparking in the day room.', true), $now)->text);
        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel.', $composer->compose($request('Need two new chairs for the kitchen.', true), $now)->text);
        $this->assertSame('Acknowledged.', $composer->compose($request('Need two new chairs for the kitchen.', false), $now)->text);

        $saturday = CarbonImmutable::parse(self::SATURDAY);
        $forwarded = $composer->compose($request('Dryer drum is making a grinding noise.', true, $saturday->subHours(3)), $saturday);
        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel. '.self::WEEKEND, $forwarded->text);
    }

    public function test_statements_about_equipment_are_answered_with_no_model_call(): void
    {
        $this->publishArticle();
        $this->enableModel();
        Http::fake();

        $this->assertSame(
            'Acknowledged. Coordinate with Captain 5 to arrange a replacement or pickup. Confirm availability before coming by.',
            $this->message('support_message', 'Missing Lifepak')->text,
        );
        $this->assertStringStartsWith('Acknowledged. Remove the leaking cylinder from use', $this->message('support_message', 'SCBA cylinder leaking')->text);
        $this->assertSame('Acknowledged.', $this->message('support_message', 'The bay door on the left side is sticking again.')->text);
        Http::assertNothingSent();
    }

    public function test_urgent_support_message_on_a_weekend_gets_the_weekend_line(): void
    {
        $composer = app(ResponseComposer::class);
        $saturday = CarbonImmutable::parse(self::SATURDAY);
        $facts = fn (string $text) => new IntakeFacts('support', 'support_message', null, [], $text, submittedAt: $saturday->subHours(2));

        $this->assertSame('Acknowledged. '.self::WEEKEND, $composer->compose($facts('Bay door remote stopped working, need it today.'), $saturday)->text);
        $this->assertSame('Acknowledged.', $composer->compose($facts('Bay door remote is getting weak.'), $saturday)->text);
    }

    public function test_bunker_gear_drop_off_is_acknowledged_only(): void
    {
        $reply = $this->message('support_message', 'Dropped off my bunker gear at Station 2 for cleaning.');

        $this->assertSame('Acknowledged.', $reply->text);
        $this->assertContains('bunker_gear_drop_off', $reply->reasonCodes);

        $exposed = $this->message('support_message', 'Left my bunker gear in the gear bin, contaminated at the fire last night.');
        $this->assertSame('Acknowledged.', $exposed->text);
        $this->assertSame('critical', $exposed->riskTier);
    }

    public function test_a_generic_left_my_message_is_not_treated_as_a_gear_drop_off(): void
    {
        $reply = $this->message('support_message', 'Left my radio at the shop.');

        $this->assertNotContains('bunker_gear_drop_off', $reply->reasonCodes);
    }

    public function test_a_hazard_message_tells_them_to_notify_the_officer_and_300(): void
    {
        $reply = $this->message('support_message', 'Charger is sparking and smells like smoke');

        $this->assertSame(self::OFFICER_300, $reply->text);
        $this->assertSame('critical', $reply->riskTier);
    }

    public function test_unit_and_station_names_do_not_change_the_answer(): void
    {
        $composer = app(ResponseComposer::class);
        $facts = new IntakeFacts('station', 'station_request', null, [], 'Engine 2 crew needs two new chairs at Fire Station 4.', 'equipment', false, null, true);

        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel.', $composer->compose($facts, CarbonImmutable::parse(self::TUESDAY))->text);
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

    public function test_free_text_the_catalog_cannot_classify_needs_a_strong_article_match_for_the_model(): void
    {
        $this->publishArticle(
            'equipment-loans',
            'Borrowing equipment for public education',
            ['loan', 'borrow equipment', 'public education'],
            'Equipment is lent only for fire safety and public education demonstrations. Ask ahead of the event with a Support Services message and wait for a yes.',
        );
        $this->enableModel();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->modelResponse('Ask ahead of the event with a Support Services message and wait for a yes.'))]);

        // A statement (no question mark) that names the article's own phrase gets a grounded answer.
        $reply = $this->message('support_message', 'We want to borrow equipment for public education at the school.');
        $this->assertSame(ComposedReply::APPROVED_RAG_GUIDANCE, $reply->decision);

        // A weak one-word overlap does not justify a model call.
        Http::fake();
        $weak = $this->message('support_message', 'Equipment room light is flickering.');
        $this->assertSame('Acknowledged.', $weak->text);
        Http::assertNothingSent();
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

    public function test_equipment_questions_the_catalog_knows_never_reach_the_model(): void
    {
        $this->publishArticle();
        $this->enableModel();
        Http::fake();

        $reply = $this->message('support_message', 'How do I refill the SCBA bottle at the station?');

        $this->assertStringContainsString('on-duty Air Tech', $reply->text);
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

    public function test_an_unpriced_model_is_never_called(): void
    {
        $this->publishArticle();
        $this->enableModel();
        config(['ai_automation.model.model' => '@cf/vendor/not-priced-yet']);
        Http::fake();

        $reply = $this->message('support_message', 'Where do I report missing kitty litter on the truck?');

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

    /** Stands in for the triage projection: defect id => level staff see. */
    private function projectedLevels(array $levels): void
    {
        $this->app->bind('App\Services\SupportTriage\TriageProjector', fn () => new class($levels)
        {
            public function __construct(private readonly array $levels) {}

            public function levelFor(string $subjectType, int $subjectId): ?string
            {
                return $this->levels[$subjectId] ?? null;
            }
        });
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

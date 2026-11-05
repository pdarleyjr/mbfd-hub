<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Services\AiAutomation\ReplyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The validator keeps model text safe and grounded without punishing accurate paraphrase. Good paraphrases of
 * the guidance must pass; invented facts, promises, contacts and document names must fail.
 */
final class ReplyValidatorTest extends TestCase
{
    private const GUIDANCE = "### Missing low-cost consumables\nReport missing kitty litter by marking it Missing on the daily checkout. Check the station for extra first, including other compartments and other units at the station. If it is still missing, call or text before coming by Station 2 so Support Services can check on a replacement. Staff cannot promise stock or a date.\n\n### Weekend\nOn Saturday and Sunday no Support Services administrative staff are on duty. Anything that can't wait goes to 300, the on-shift Division Chief.";

    /** @return array<string, array{0: string, 1: string}> */
    public static function goodParaphrases(): array
    {
        return [
            'close to the source' => ['Where do I report missing kitty litter?', 'Mark the kitty litter as Missing on your daily checkout. Check the station for extra first.'],
            'reworded steps' => ['The kitty litter is gone from the truck. What do I do?', "Look around the station for extra first, and if you can't find any, call or text before coming to Station 2."],
            'different word forms' => ['Our truck is out of kitty litter', 'Mark it Missing when you do the checkout, then look through the other compartments and the other units at the station.'],
            'weekend' => ['Is anyone in the office on Sunday?', "No Support Services staff are on duty on Saturday and Sunday. If it can't wait, go through 300."],
            'uses the member own item name' => ['Where do I report a missing spill absorbent bag?', 'Mark the absorbent bag as Missing on the daily checkout and check the station for extra first.'],
            'short' => ['Do I need to call first?', 'Yes, call or text before coming by Station 2.'],
        ];
    }

    #[DataProvider('goodParaphrases')]
    public function test_accurate_paraphrases_pass(string $question, string $answer): void
    {
        $this->assertSame($answer, (new ReplyValidator)->validateModelAnswer($answer, self::GUIDANCE, $question));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function hallucinations(): array
    {
        return [
            'invented place' => ['Where do I get more kitty litter?', 'Replacements are kept in the north supply cage and you can pick one up from the second floor office.'],
            'invented person' => ['Who handles kitty litter?', 'Talk to the logistics sergeant, he keeps the extra stock in his locker.'],
            'invented rule' => ['Can I borrow a generator?', 'Generators can be borrowed for a week with a signed form from the battalion commander.'],
            'unrelated advice' => ['Where do I report missing kitty litter?', 'Go see the chief about it when you get a chance.'],
            'only plain words' => ['Where do I report missing kitty litter?', 'Please check first and then call.'],
            'number not in the guidance' => ['How long does a replacement take?', 'Replacements usually arrive within 3 days.'],
            'phone number' => ['Who do I call?', 'Call 305-555-0100 before coming by.'],
            'promise' => ['Will I get a replacement?', 'Yes, a replacement is in stock and will be ready for you.'],
            'document name' => ['Where do I report it?', 'According to the procedure, mark it Missing on the daily checkout.'],
            'link' => ['Where do I report it?', 'See www.example.org for the checkout steps.'],
            'pleasantry' => ['Where do I report it?', 'Thanks for asking! Mark it Missing on the daily checkout.'],
            'too many sentences' => ['Where do I report it?', 'Mark it Missing. Check the station. Call or text first. Then come by Station 2.'],
            'list format' => ['Where do I report it?', "- Mark it Missing\n- Check the station"],
            'no answer marker' => ['Where do I report it?', 'NO_ANSWER'],
            'empty' => ['Where do I report it?', '   '],
        ];
    }

    #[DataProvider('hallucinations')]
    public function test_invented_or_unsafe_answers_fail(string $question, string $answer): void
    {
        $this->assertNull((new ReplyValidator)->validateModelAnswer($answer, self::GUIDANCE, $question));
    }

    public function test_the_old_70_percent_rule_would_have_refused_this_accurate_answer(): void
    {
        // Most of these content words ("remember", "report", "inspection", "afterwards") are not in the guidance,
        // but each is a plain word or the member's own; the answer adds no new fact.
        $answer = 'Mark the kitty litter Missing on the checkout, then call or text before coming to Station 2.';

        $this->assertSame($answer, (new ReplyValidator)->validateModelAnswer($answer, self::GUIDANCE, 'Report kitty litter'));
        $this->assertSame([], (new ReplyValidator)->unknownWords($answer, self::GUIDANCE, 'Report kitty litter'));
    }

    public function test_unknown_words_lists_what_was_not_grounded(): void
    {
        $unknown = (new ReplyValidator)->unknownWords('Talk to the logistics sergeant about the kitty litter.', self::GUIDANCE);

        $this->assertContains('sergeant', $unknown);
        $this->assertNotContains('kitty', $unknown);
    }

    public function test_a_name_the_member_used_is_allowed_but_not_invented(): void
    {
        $validator = new ReplyValidator;
        $answer = 'Mark the spill absorbent as Missing on the daily checkout.';

        $this->assertNull($validator->validateModelAnswer($answer, self::GUIDANCE, 'Where do I report this?'));
        $this->assertSame($answer, $validator->validateModelAnswer($answer, self::GUIDANCE, 'Where do I report missing spill absorbent?'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Enums\AccountStatus;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Input normalization, request ids, rate limits and actor authorization of the messaging service. */
final class EmployeeLogisticsMessagingInputTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------ body

    /** @return array<string, array{0: string}> */
    public static function blankBodies(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['     '],
            'newlines and tabs' => ["\n\t \r\n\n"],
            'only control characters' => ["\x00\x07\x1B\x7F"],
            'only a no-break control and spaces' => [" \u{0085} "],
        ];
    }

    #[DataProvider('blankBodies')]
    public function test_a_blank_body_is_rejected_and_nothing_is_written(string $body): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $responder = $this->logisticsResponder();

        $this->assertInvalid(fn () => $this->service()->startThread($member, $body, null, $this->rid()));
        $this->assertInvalid(fn () => $this->service()->replyAsMember($member, $first->thread_id, $body, $this->rid()));
        $this->assertInvalid(fn () => $this->service()->replyAsLogistics($responder, $first->thread_id, $body, $this->rid()));

        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
        $this->assertSame(1, $this->storedThread($first)->message_count);
    }

    public function test_the_body_length_limit_is_5000_characters(): void
    {
        $member = $this->messagingMember();

        $accepted = $this->service()->startThread($member, str_repeat('a', 5000), null, $this->rid());
        $this->assertSame(5000, mb_strlen($accepted->fresh()->body));

        $this->assertInvalid(fn () => $this->service()->startThread($member, str_repeat('a', 5001), null, $this->rid()));
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_the_limit_counts_characters_not_bytes(): void
    {
        $member = $this->messagingMember();
        $emoji = "\u{1F600}";

        $accepted = $this->service()->startThread($member, str_repeat($emoji, 5000), null, $this->rid());
        $this->assertSame(str_repeat($emoji, 5000), $accepted->fresh()->body);

        $this->assertInvalid(fn () => $this->service()->startThread($member, str_repeat($emoji, 5001), null, $this->rid()));
    }

    public function test_the_limit_applies_after_trimming_and_to_replies_on_both_sides(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $first = $this->memberSends($member);

        $padded = $this->service()->replyAsMember($member, $first->thread_id, "  \n".str_repeat('b', 5000)."\n  ", $this->rid());
        $this->assertSame(5000, mb_strlen($padded->body));

        $this->assertInvalid(fn () => $this->service()->replyAsMember($member, $first->thread_id, str_repeat('b', 5001), $this->rid()));
        $this->assertInvalid(fn () => $this->service()->replyAsLogistics($responder, $first->thread_id, str_repeat('b', 5001), $this->rid()));
        $this->assertSame(5000, mb_strlen($this->logisticsReplies($responder, $first, str_repeat('c', 5000))->body));
    }

    public function test_unicode_and_emoji_round_trip_byte_exact(): void
    {
        $member = $this->messagingMember();
        $text = "Caf\u{00E9} \u{2615} \u{1F600} \u{65E5}\u{672C}\u{8A9E} \u{0645}\u{0631}\u{062D}\u{0628}\u{0627} e\u{0301} \u{1F468}\u{200D}\u{1F692}";

        $message = $this->service()->startThread($member, $text, null, $this->rid());

        $this->assertSame($text, EmployeeLogisticsMessage::query()->findOrFail($message->id)->body);
        $this->assertSame(bin2hex($text), bin2hex(DB::table('employee_logistics_messages')->where('id', $message->id)->value('body')));
    }

    public function test_markup_and_sql_text_are_stored_verbatim_escaping_is_a_view_concern(): void
    {
        $member = $this->messagingMember();
        $payloads = [
            '<script>alert(1)</script>',
            '<img src=x onerror="alert(1)"> &amp; "quotes" \'single\'',
            "Robert'); DROP TABLE employee_logistics_messages;--",
            '{{ 7 * 7 }} @php echo 1 @endphp {!! $x !!}',
        ];

        foreach ($payloads as $payload) {
            $message = $this->service()->startThread($member, $payload, null, $this->rid());
            $this->assertSame($payload, EmployeeLogisticsMessage::query()->findOrFail($message->id)->body);
        }
        $this->assertSame(count($payloads), EmployeeLogisticsMessage::query()->count());
    }

    public function test_control_characters_are_stripped_but_tabs_and_newlines_survive(): void
    {
        $member = $this->messagingMember();

        $message = $this->service()->startThread($member, "a\x00b\x07c\x1Bd\x7Fe\u{0085}f\tg\nh", null, $this->rid());

        $this->assertSame("abcdef\tg\nh", $message->body);
    }

    public function test_line_endings_are_normalized_to_a_single_newline(): void
    {
        $member = $this->messagingMember();

        $message = $this->service()->startThread($member, "one\r\ntwo\rthree\nfour", null, $this->rid());

        $this->assertSame("one\ntwo\nthree\nfour", $message->body);
    }

    public function test_surrounding_whitespace_is_trimmed_but_inner_spacing_is_kept(): void
    {
        $member = $this->messagingMember();

        $message = $this->service()->startThread($member, "  \n  hello   there\n\nbye  \n ", null, $this->rid());

        $this->assertSame("hello   there\n\nbye", $message->body);
    }

    public function test_invalid_utf8_is_rejected_not_stored_mangled(): void
    {
        $member = $this->messagingMember();

        $this->assertInvalid(fn () => $this->service()->startThread($member, "bad \xC3\x28 bytes", null, $this->rid()));
        $this->assertInvalid(fn () => $this->service()->startThread($member, "\xFF\xFE", null, $this->rid()));
        $this->assertSame(0, EmployeeLogisticsMessage::query()->count());
    }

    public function test_the_preview_is_a_single_short_line(): void
    {
        $member = $this->messagingMember();
        $long = 'word '.str_repeat('x', 400);

        $thread = $this->storedThread($this->service()->startThread($member, $long, null, $this->rid()));
        $this->assertLessThanOrEqual(140, mb_strlen($thread->last_message_preview));
        $this->assertStringEndsWith("\u{2026}", $thread->last_message_preview);
        $this->assertStringStartsWith('word xxx', $thread->last_message_preview);

        $flat = $this->storedThread($this->service()->startThread($member, "line one\n\n   line\ttwo", null, $this->rid()));
        $this->assertSame('line one line two', $flat->last_message_preview);
    }

    public function test_the_preview_is_exact_at_the_boundary_and_never_splits_a_character(): void
    {
        $member = $this->messagingMember();

        $exact = $this->storedThread($this->service()->startThread($member, str_repeat('p', 140), null, $this->rid()));
        $this->assertSame(str_repeat('p', 140), $exact->last_message_preview);

        $over = $this->storedThread($this->service()->startThread($member, str_repeat("\u{1F600}", 141), null, $this->rid()));
        $this->assertTrue(mb_check_encoding($over->last_message_preview, 'UTF-8'));
        $this->assertSame(140, mb_strlen($over->last_message_preview));
    }

    // ------------------------------------------------------------ subject

    public function test_a_subject_of_120_characters_is_accepted_and_121_is_rejected(): void
    {
        $member = $this->messagingMember();

        $ok = $this->service()->startThread($member, 'Body', str_repeat('s', 120), $this->rid());
        $this->assertSame(str_repeat('s', 120), $this->storedThread($ok)->subject);

        $this->assertInvalid(fn () => $this->service()->startThread($member, 'Body', str_repeat('s', 121), $this->rid()), 'subject');
        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_the_subject_limit_counts_characters_after_collapsing_whitespace(): void
    {
        $member = $this->messagingMember();

        $ok = $this->service()->startThread($member, 'Body', '  '.str_repeat("\u{00E9}", 120).'  ', $this->rid());
        $this->assertSame(str_repeat("\u{00E9}", 120), $this->storedThread($ok)->subject);

        $this->assertInvalid(fn () => $this->service()->startThread($member, 'Body', str_repeat("\u{00E9}", 121), $this->rid()), 'subject');
    }

    #[DataProvider('blankSubjects')]
    public function test_a_blank_subject_is_stored_as_null(?string $subject): void
    {
        $thread = $this->storedThread($this->service()->startThread($this->messagingMember(), 'Body', $subject, $this->rid()));

        $this->assertNull($thread->subject);
        $this->assertSame('Message to Support Services', $thread->title());
    }

    /** @return array<string, array{0: ?string}> */
    public static function blankSubjects(): array
    {
        return ['null' => [null], 'empty' => [''], 'spaces' => ['    '], 'control characters' => ["\x00\n\t"], 'invalid utf-8' => ["\xC3\x28"]];
    }

    public function test_subject_whitespace_and_control_characters_are_collapsed_to_single_spaces(): void
    {
        $thread = $this->storedThread($this->service()->startThread(
            $this->messagingMember(), 'Body', "  Boots \t\n arrived\x00damaged  ", $this->rid(),
        ));

        $this->assertSame('Boots arrived damaged', $thread->subject);
    }

    public function test_an_invalid_subject_blocks_the_whole_send_before_a_thread_exists(): void
    {
        $member = $this->messagingMember();

        $this->assertInvalid(fn () => $this->service()->startThread($member, 'Body', str_repeat('x', 500), $this->rid()), 'subject');

        $this->assertSame(0, EmployeeLogisticsThread::query()->count());
    }

    // ------------------------------------------------------------ client request id

    /** @return array<string, array{0: string}> */
    public static function invalidRequestIds(): array
    {
        return [
            'one character' => ['x'],
            'seven characters' => ['abcdefg'],
            'empty' => [''],
            'spaces inside' => ['has space in it'],
            'sixty five characters' => [str_repeat('a', 65)],
            'underscore' => ['under_score_id'],
            'slash' => ['id-with/slash-12'],
            'tab inside' => ["tab\tinside-1234"],
            'non ascii' => ["\u{00E9}moji-id-12345"],
            'quote' => ["abc'def-ghijkl"],
            'newline inside' => ["abcdefgh\nijklmnop"],
        ];
    }

    #[DataProvider('invalidRequestIds')]
    public function test_a_malformed_request_id_is_rejected_on_every_send_path(string $requestId): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $responder = $this->logisticsResponder();

        $this->assertInvalid(fn () => $this->service()->startThread($member, 'Body', null, $requestId));
        $this->assertInvalid(fn () => $this->service()->replyAsMember($member, $first->thread_id, 'Body', $requestId));
        $this->assertInvalid(fn () => $this->service()->replyAsLogistics($responder, $first->thread_id, 'Body', $requestId));

        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function validRequestIds(): array
    {
        return [
            'eight characters' => ['abcdefgh', 'abcdefgh'],
            'sixty four characters' => [str_repeat('Ab1-', 16), str_repeat('Ab1-', 16)],
            'uuid' => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301', '3f2504e0-4f89-41d3-9a0c-0305e82c3301'],
            'padded with whitespace' => ["  abcdefgh12  \n", 'abcdefgh12'],
        ];
    }

    #[DataProvider('validRequestIds')]
    public function test_a_well_formed_request_id_is_accepted_and_stored_trimmed(string $given, string $stored): void
    {
        $message = $this->service()->startThread($this->messagingMember(), 'Body', null, $given);

        $this->assertSame($stored, EmployeeLogisticsMessage::query()->findOrFail($message->id)->client_request_id);
    }

    // ------------------------------------------------------------ rate limits

    public function test_a_member_may_send_ten_messages_a_minute_and_the_eleventh_is_refused(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        for ($i = 2; $i <= 10; $i++) {
            $this->memberReplies($member, $first, 'message '.$i);
        }

        $exception = $this->assertInvalid(fn () => $this->memberReplies($member, $first, 'message 11'));

        $this->assertStringContainsString('too quickly', $exception->errors()['body'][0]);
        $this->assertStringContainsString('still here', $exception->errors()['body'][0]);
        $this->assertSame(10, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(10, $this->storedThread($first)->message_count);
    }

    public function test_starting_threads_shares_the_same_member_budget(): void
    {
        $member = $this->messagingMember();
        for ($i = 1; $i <= 10; $i++) {
            $this->memberSends($member, 'topic '.$i);
        }

        $this->assertInvalid(fn () => $this->memberSends($member, 'topic 11'));
        $this->assertSame(10, EmployeeLogisticsThread::query()->count());
    }

    public function test_the_member_budget_is_per_user_and_recovers_after_a_minute(): void
    {
        $member = $this->messagingMember('Busy Member');
        $other = $this->messagingMember('Calm Member');
        for ($i = 1; $i <= 10; $i++) {
            $this->memberSends($member, 'topic '.$i);
        }
        $this->assertInvalid(fn () => $this->memberSends($member, 'blocked'));

        $this->assertSame('calm', $this->memberSends($other, 'calm')->body, 'Another member is unaffected.');

        $this->travel(61)->seconds();
        $this->assertSame('after the wait', $this->memberSends($member, 'after the wait')->body);
    }

    public function test_clearing_the_limiter_key_restores_the_budget(): void
    {
        $member = $this->messagingMember();
        for ($i = 1; $i <= 10; $i++) {
            $this->memberSends($member, 'topic '.$i);
        }
        $this->assertInvalid(fn () => $this->memberSends($member, 'blocked'));

        RateLimiter::clear('employee-logistics-message:'.$member->getKey());

        $this->assertSame('unblocked', $this->memberSends($member, 'unblocked')->body);
    }

    public function test_retries_of_an_already_saved_message_never_use_the_budget(): void
    {
        $member = $this->messagingMember();
        $requestId = $this->rid();
        $saved = $this->service()->startThread($member, 'topic 1', null, $requestId);
        for ($i = 2; $i <= 10; $i++) {
            $this->memberSends($member, 'topic '.$i);
        }

        $this->assertSame($saved->id, $this->service()->startThread($member, 'topic 1', null, $requestId)->id, 'A confirmed retry succeeds even at the limit.');
        $this->assertInvalid(fn () => $this->memberSends($member, 'topic 11'));
    }

    public function test_rejected_input_does_not_use_the_budget(): void
    {
        $member = $this->messagingMember();
        for ($i = 0; $i < 15; $i++) {
            $this->assertInvalid(fn () => $this->service()->startThread($member, '   ', null, $this->rid()));
        }

        for ($i = 1; $i <= 10; $i++) {
            $this->memberSends($member, 'topic '.$i);
        }
        $this->assertSame(10, EmployeeLogisticsThread::query()->count());
    }

    public function test_logistics_may_send_thirty_replies_a_minute_and_the_thirty_first_is_refused(): void
    {
        $responder = $this->logisticsResponder();
        $threads = [];
        for ($i = 1; $i <= 3; $i++) {
            $threads[] = $this->memberSends($this->messagingMember('Member '.$i));
        }
        for ($i = 0; $i < 30; $i++) {
            $this->logisticsReplies($responder, $threads[$i % 3], 'reply '.$i);
        }

        $this->assertInvalid(fn () => $this->logisticsReplies($responder, $threads[0], 'reply 30'));
        $this->assertSame(33, EmployeeLogisticsMessage::query()->count());

        $this->assertSame('other responder', $this->logisticsReplies($this->logisticsResponder(), $threads[0], 'other responder')->body);
    }

    // ------------------------------------------------------------ actor authorization

    public function test_an_administrator_without_the_capability_cannot_use_the_logistics_side(): void
    {
        $first = $this->memberSends($this->messagingMember());
        $admin = $this->unrelatedAdmin();

        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->logisticsThreads($admin));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->logisticsThread($admin, $first->thread_id));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->markReadByLogistics($admin, $first->thread_id, $first->id));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($admin, $first->thread_id, 'Hi', $this->rid()));
        $this->assertSame(0, $this->service()->logisticsUnreadCount($admin), 'Counts never reveal that mail exists.');

        $thread = $this->storedThread($first);
        $this->assertNull($thread->logistics_read_message_id);
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_authorization_is_checked_before_the_input_is_validated(): void
    {
        $first = $this->memberSends($this->messagingMember());

        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($this->unrelatedAdmin(), $first->thread_id, '', 'x'));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->startThread($this->unrelatedAdmin(), '', null, 'x'));
    }

    public function test_a_view_only_administrator_can_read_but_not_reply(): void
    {
        $first = $this->memberSends($this->messagingMember());
        $viewer = $this->logisticsViewer();

        $this->assertCount(1, $this->service()->logisticsThreads($viewer)['threads']);
        $this->assertSame(1, $this->service()->logisticsUnreadCount($viewer));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($viewer, $first->thread_id, 'Hi', $this->rid()));
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_plain_member_cannot_use_the_logistics_side(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);

        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->logisticsThreads($member));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->logisticsThread($member, $first->thread_id));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->markReadByLogistics($member, $first->thread_id, $first->id));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($member, $first->thread_id, 'Self reply', $this->rid()));
        $this->assertSame(0, $this->service()->logisticsUnreadCount($member));
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_an_account_without_a_personnel_profile_cannot_use_the_member_side(): void
    {
        $first = $this->memberSends($this->messagingMember());
        foreach ([$this->logisticsResponder(), $this->messagingAdmin(['super_admin'])] as $admin) {
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->startThread($admin, 'Hello', null, $this->rid()));
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsMember($admin, $first->thread_id, 'Hello', $this->rid()));
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->memberThreads($admin));
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->memberThread($admin, $first->thread_id));
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->markReadByMember($admin, $first->thread_id, $first->id));
            $this->assertSame(0, $this->service()->memberUnreadCount($admin));
        }
        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
    }

    public function test_a_disabled_member_is_refused_everywhere(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->logisticsReplies($this->logisticsResponder(), $first);

        $member->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->assertMemberSideRefused($member, $first);
    }

    public function test_a_departed_member_is_refused_everywhere(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->logisticsReplies($this->logisticsResponder(), $first);

        DB::table('employees')->where('id', $member->employee_profile_id)->update(['roster_status' => 'departed']);

        $this->assertMemberSideRefused($member->fresh(), $first);
    }

    public function test_a_member_with_a_pending_password_change_is_refused_everywhere(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->logisticsReplies($this->logisticsResponder(), $first);

        $member->forceFill(['must_change_password' => true])->save();

        $this->assertMemberSideRefused($member, $first);
    }

    public function test_a_responder_who_loses_access_is_refused_on_the_very_next_call(): void
    {
        $responder = $this->logisticsResponder();
        $first = $this->memberSends($this->messagingMember());
        $this->assertCount(1, $this->service()->logisticsThreads($responder)['threads']);

        $responder->removeRole('logistics_admin');
        $responder = $responder->fresh();

        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->logisticsThreads($responder));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($responder, $first->thread_id, 'Late', $this->rid()));
    }

    public function test_a_disabled_responder_is_refused(): void
    {
        $first = $this->memberSends($this->messagingMember());
        $responder = $this->logisticsResponder(['account_status' => AccountStatus::Disabled]);
        $superAdmin = $this->messagingAdmin(['super_admin'], [], ['account_status' => AccountStatus::Disabled]);

        foreach ([$responder, $superAdmin] as $actor) {
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->logisticsThreads($actor));
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($actor, $first->thread_id, 'Hi', $this->rid()));
            $this->assertSame(0, $this->service()->logisticsUnreadCount($actor));
        }
    }

    private function assertMemberSideRefused(\App\Models\User $member, EmployeeLogisticsMessage $in): void
    {
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->startThread($member, 'Hello', null, $this->rid()));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsMember($member, $in->thread_id, 'Hello', $this->rid()));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->memberThreads($member));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->memberThread($member, $in->thread_id));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->markReadByMember($member, $in->thread_id, 999));
        $this->assertSame(0, $this->service()->memberUnreadCount($member), 'A refused account sees no unread badge.');
    }
}

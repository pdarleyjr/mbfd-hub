<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Enums\AccountStatus;
use App\Jobs\SendMemberPasswordLink;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\EquipmentDefectNotification;
use App\Notifications\NewSubmissionNotification;
use App\Notifications\SupportServices\SupportServicesMessageNotification;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Identity\CityEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Feature\Deliverability\Concerns\SendsThroughCloudflareFake;
use Tests\TestCase;

/** What each sender actually puts in front of a recipient: subject, sign-off, document shape and links. */
final class SenderBodiesTest extends TestCase
{
    use RefreshDatabase;
    use SendsThroughCloudflareFake;

    private const SIGN_OFF = "\n\n--\nMiami Beach Fire Department\nMBFD Hub";

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSending();
    }

    public function test_a_notification_email_gets_a_sign_off_and_a_valid_html_document_without_changing_its_content(): void
    {
        $notification = new NewSubmissionNotification('station_request', 'New Station Request', 'Needs a new hose & nozzle', '/admin/station-requests/7');
        $notification->id = (string) Str::uuid();

        $this->deliver($notification);

        $request = $this->onlyRequest();
        $url = url('/admin/station-requests/7');
        $legacyText = "Needs a new hose & nozzle\n\nView in MBFD Hub: {$url}";
        // The link now closes cleanly: it used to end in a stray backslash before the closing quote.
        $legacyHtml = '<h1>New Station Request</h1><p>Needs a new hose &amp; nozzle</p><p><a href="'.e($url).'">View in MBFD Hub</a></p>';
        self::assertSame('New Station Request', $request['subject']);
        self::assertStringStartsWith($legacyText."\n\n--\nMiami Beach Fire Department\n", $request['text']);
        self::assertStringStartsWith('<!DOCTYPE html>', $request['html']);
        self::assertStringContainsString('<html lang="en">', $request['html']);
        self::assertStringContainsString($legacyHtml, $request['html']);
        self::assertSame($request['text'], OutboundEmail::query()->sole()->text_body, 'a non-sensitive notification is stored as sent');
    }

    public function test_a_blank_notification_title_still_sends_with_a_fallback_subject(): void
    {
        $notification = new class extends Notification
        {
            public function toBudgetedEmail(object $notifiable): array
            {
                return ['subject' => "  \t ", 'text' => 'Operational detail'];
            }
        };
        $notification->id = (string) Str::uuid();

        $this->deliver($notification);

        self::assertSame('MBFD Hub notification', $this->onlyRequest()['subject']);
    }

    public function test_the_equipment_defect_subject_and_body_have_no_all_caps_tokens_or_em_dash(): void
    {
        foreach (['damaged', 'missing'] as $type) {
            $mail = (new EquipmentDefectNotification($type, 'Hurst Saw', 'T-1', 'Engine 1', 'Member Name', 'Cracked housing', 'REF-1'))->toBudgetedEmail(new stdClass);

            self::assertSame("Equipment {$type}: Hurst Saw (T-1) on Engine 1", $mail['subject']);
            self::assertDoesNotMatchRegularExpression('/\b(DAMAGED|MISSING)\b/', $mail['subject'].$mail['text']);
            self::assertStringNotContainsString("\u{2014}", $mail['text']);
            self::assertStringContainsString('Notes: Cracked housing', $mail['text']);
        }
    }

    #[DataProvider('snipeItUrls')]
    public function test_the_equipment_link_removes_only_the_api_suffix(string $configured, string $expected): void
    {
        config(['snipeit.url' => $configured]);

        $mail = (new EquipmentDefectNotification('damaged', 'Saw', 'T-1', 'Engine 1', 'Member', '', 'REF-1'))->toBudgetedEmail(new stdClass);

        self::assertStringContainsString("View in Snipe-IT: {$expected}\n", $mail['text']."\n");
    }

    /** @return array<string, array{string, string}> */
    public static function snipeItUrls(): array
    {
        return [
            'production api url' => ['https://inventory.mbfdhub.com/api/v1', 'https://inventory.mbfdhub.com'],
            'trailing slash' => ['https://inventory.mbfdhub.com/api/v1/', 'https://inventory.mbfdhub.com'],
            'already a base url' => ['https://inventory.mbfdhub.com', 'https://inventory.mbfdhub.com'],
            'host ending in characters of the old mask' => ['https://snipe.example.pa/api/v1/', 'https://snipe.example.pa'],
            'path ending in mask characters' => ['https://example.test/apps/api/v1', 'https://example.test/apps'],
        ];
    }

    public function test_support_services_subjects_name_the_conversation_number_and_nothing_else(): void
    {
        foreach ([[SupportServicesMessageNotification::AUDIENCE_SUPPORT, 'New Support Services message (conversation #42)'],
            [SupportServicesMessageNotification::AUDIENCE_MEMBER, 'Support Services replied (conversation #42)']] as [$audience, $subject]) {
            $mail = (new SupportServicesMessageNotification($audience, 42, 9001, 'Gia Gear', 'Bunker gear cleaning', 'Turnout coat zipper'))->toBudgetedEmail(new stdClass);

            self::assertSame($subject, $mail['subject']);
            foreach (['Gia', 'Gear', 'Bunker', 'Turnout', 'zipper', '9001'] as $leak) {
                self::assertStringNotContainsString($leak, json_encode($mail, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_two_conversations_no_longer_share_one_support_subject(): void
    {
        $first = (new SupportServicesMessageNotification('support', 1, 10, 'A', null, 'x'))->toBudgetedEmail(new stdClass)['subject'];
        $second = (new SupportServicesMessageNotification('support', 2, 11, 'A', null, 'x'))->toBudgetedEmail(new stdClass)['subject'];

        self::assertNotSame($first, $second);
    }

    public function test_a_request_number_already_known_to_the_lock_screen_distinguishes_the_submission_subject(): void
    {
        $with = new NewSubmissionNotification('uniform_request', 'Uniform request update', 'Body', '/admin', messageBearing: true, pushSubject: 'UNI-2026-014');
        $already = new NewSubmissionNotification('uniform_request', 'UNI-2026-014: update', 'Body', '/admin', messageBearing: true, pushSubject: 'UNI-2026-014');
        $unsafe = new NewSubmissionNotification('uniform_request', 'Uniform request update', 'Body', '/admin', messageBearing: true, pushSubject: "<script>alert('x')</script>");
        $none = new NewSubmissionNotification('uniform_request', 'Uniform request update', 'Body', '/admin');

        self::assertSame('Uniform request update (UNI-2026-014)', $with->toBudgetedEmail(new stdClass)['subject']);
        self::assertSame('UNI-2026-014: update', $already->toBudgetedEmail(new stdClass)['subject']);
        self::assertSame('Uniform request update', $unsafe->toBudgetedEmail(new stdClass)['subject']);
        self::assertSame('Uniform request update', $none->toBudgetedEmail(new stdClass)['subject']);
    }

    public function test_the_password_link_keeps_its_token_and_text_only_form_and_only_gains_the_sign_off(): void
    {
        $member = $this->member();
        $job = new SendMemberPasswordLink($member->id, $member->security_version);

        $job->handle(app(CityEmailVerificationService::class), app(CloudflareEmailDispatcher::class));

        $request = $this->onlyRequest();
        self::assertArrayNotHasKey('html', $request);
        self::assertStringEndsWith(self::SIGN_OFF, $request['text']);
        $original = substr($request['text'], 0, -strlen(self::SIGN_OFF));
        self::assertSame(1, preg_match('#^(.+Choose your password: )(\S+/reset-password/([^?\s]+)\?employee_id=\S+)(\n\nThis link works once.+)$#s', $original, $parts));
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), rawurldecode($parts[3])), 'the link must still carry the live token');
        self::assertSame('[Sensitive account-security message omitted]', OutboundEmail::query()->sole()->text_body);
        self::assertNull(OutboundEmail::query()->sole()->html_body);
    }

    public function test_city_email_security_messages_keep_their_exact_wording_plus_the_sign_off(): void
    {
        $employee = Employee::query()->create(['employee_id' => 'deliverability-city', 'name' => 'City Email Member', 'password' => 'employee-fixture', 'must_change_password' => false]);
        $member = User::factory()->create([
            'name' => $employee->name, 'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active, 'email' => 'employee-'.$employee->id.'@canonical.mbfdhub.invalid', 'email_verified_at' => null,
        ]);
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($member, 'old@miamibeachfl.gov');
        $service->issue($member);
        $verification = Http::recorded()->last()[0];
        self::assertStringEndsWith(self::SIGN_OFF, $verification['text']);
        self::assertSame(1, preg_match('~/verify/([a-f0-9]{64})~', $verification['text'], $match));
        $link = url('/account/city-email/verify/'.$match[1]);
        self::assertSame(
            "Sign in to your MBFD Hub account and confirm this email address using the link below. The link expires in 30 minutes.\n\n{$link}\n\nIf you did not request this message, you can ignore it.".self::SIGN_OFF,
            $verification['text'],
        );
        self::assertArrayNotHasKey('html', $verification->data());

        self::assertTrue($service->verify($member, $match[1]));
        $service->acknowledge($member, 'new@miamibeachfl.gov');
        $service->issue($member);
        preg_match('~/verify/([a-f0-9]{64})~', Http::recorded()->last()[0]['text'], $second);
        self::assertTrue($service->verify($member, $second[1]));

        $notice = Http::recorded()->first(fn (array $pair): bool => $pair[0]['subject'] === 'Your MBFD Hub city email changed')[0];
        self::assertStringEndsWith(self::SIGN_OFF, $notice['text']);
        self::assertStringContainsString('If you did not make this change, contact your MBFD Hub administrator.', $notice['text']);
        self::assertArrayNotHasKey('html', $notice->data());
    }

    /** The senders without a cheap harness here are pinned at source level so none is quietly missed. */
    #[DataProvider('securitySenders')]
    public function test_every_account_security_sender_signs_its_text_through_the_shared_formatter(string $file): void
    {
        $source = (string) file_get_contents(base_path($file));

        self::assertStringContainsString('TransactionalMessageFormatter', $source);
        self::assertStringContainsString('signOffText(', $source);
        self::assertStringNotContainsString('->notification(', $source, 'security mail is text-only and is never given an HTML document');
    }

    /** @return array<string, array{string}> */
    public static function securitySenders(): array
    {
        return [
            'password reset page' => ['app/Http/Controllers/Auth/EmployeePasswordResetController.php'],
            'password link job' => ['app/Jobs/SendMemberPasswordLink.php'],
            'administrative recovery' => ['app/Services/Security/AccountSecurityService.php'],
            'city email' => ['app/Services/Identity/CityEmailVerificationService.php'],
            'onboarding invitation' => ['app/Services/Identity/MemberOnboardingInvitationService.php'],
            'activation notice' => ['app/Services/Identity/MemberSelfServiceActivation.php'],
        ];
    }

    private function deliver(Notification $notification): void
    {
        app(BudgetedMailChannel::class)->send((new AnonymousNotifiable)->route('mail', 'member@miamibeachfl.gov'), $notification);
    }

    private function member(): User
    {
        $employee = Employee::query()->create([
            'employee_id' => 'DELIVERABILITY-1', 'name' => 'Deliverability Member', 'roster_status' => 'active',
            'city_email' => 'old@miamibeachfl.gov', 'password' => 'unused-legacy-password',
        ]);
        config(['identity.credential_authority' => 'local']);

        return User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'email' => 'old@miamibeachfl.gov', 'account_status' => AccountStatus::Active, 'security_version' => 1,
        ]);
    }
}

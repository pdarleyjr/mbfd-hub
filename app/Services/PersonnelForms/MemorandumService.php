<?php

declare(strict_types=1);

namespace App\Services\PersonnelForms;

use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\Personnel\PersonnelDocumentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * MBFD Interoffice Memorandum.
 *
 * - Addressed outside the Hub: the PDF is produced on request and handed to the member. Nothing is stored and
 *   nothing reaches administrators. "Email a copy to my City email" sends it through the department sender
 *   only to the member's own verified City address; the Email Center keeps no copy of the body or file.
 * - Addressed to Support Services: delivered into the existing Support Services inbox as a conversation, with
 *   the PDF kept as one personnel document that the member also sees in My Documents.
 */
final class MemorandumService
{
    public const SUPPORT_SERVICES = 'Support Services';

    public function __construct(
        private readonly PersonnelFormRenderer $renderer,
        private readonly EmployeeLogisticsMessagingService $messaging,
        private readonly PersonnelDocumentService $documents,
    ) {}

    /** @return array{to:string, from:string, date:string, subject:string, body:string} */
    public function validate(array $input, bool $toSupportServices = false): array
    {
        $data = Validator::make($input, [
            'to' => [$toSupportServices ? 'nullable' : 'required', 'string', 'max:200'],
            'from' => ['required', 'string', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ], [
            'to.required' => 'Enter who the memorandum is to.',
            'subject.required' => 'Enter a subject.',
            'body.required' => 'Write the memorandum.',
        ])->validate();
        if ($toSupportServices) {
            $data['to'] = self::SUPPORT_SERVICES;
        }

        return array_map(static fn (string $value): string => trim($value), $data);
    }

    public function render(array $memo): string
    {
        return $this->renderer->render(PersonnelFormRenderer::MEMORANDUM, [
            'to' => $memo['to'],
            'from' => $memo['from'],
            'date' => Carbon::createFromFormat('Y-m-d', $memo['date'])->format('F j, Y'),
            'subject' => $memo['subject'],
            'body' => $memo['body'],
        ], [], ['title' => 'Interoffice Memorandum: '.Str::limit($memo['subject'], 80), 'subject' => Str::limit($memo['subject'], 90)]);
    }

    public function fileName(array $memo): string
    {
        return 'MBFD Memorandum - '.Str::limit(preg_replace('/[^A-Za-z0-9 ._-]+/', '', $memo['subject']) ?: 'Memo', 60, '').' - '.$memo['date'].'.pdf';
    }

    /**
     * Delivers the memorandum to the Support Services inbox. Repeating the call with the same client key (retry,
     * double tap, dropped connection) returns the same conversation and never sends a second one.
     */
    public function sendToSupportServices(User $member, array $input, string $clientKey): EmployeeLogisticsMessage
    {
        $employee = $member->employeeProfile;
        abort_unless($employee instanceof Employee, 403);
        // Inside the Hub the sender is the signed-in member: "From" is not free text here.
        $memo = $this->validate(['from' => self::fromLine($employee)] + $input, true);
        $bytes = $this->render($memo);
        $intro = "Interoffice Memorandum\nTo: Support Services\nFrom: {$memo['from']}\nDate: "
            .Carbon::createFromFormat('Y-m-d', $memo['date'])->format('F j, Y')."\nSubject: {$memo['subject']}\n\n";
        $note = "\n\n(The signed-in member sent this memorandum from the Hub. The PDF is attached to this conversation.)";
        $body = Str::limit($intro.$memo['body'], 5000 - strlen($note) - 3).$note;
        $message = $this->messaging->startThread($member, $body, Str::limit('Memo: '.$memo['subject'], 120, ''), $clientKey);

        return DB::transaction(function () use ($message, $bytes, $memo, $member, $employee): EmployeeLogisticsMessage {
            $locked = EmployeeLogisticsMessage::query()->lockForUpdate()->findOrFail($message->getKey());
            if ($locked->personnel_document_id !== null) {
                return $locked;
            }
            $document = $this->documents->storeGeneratedPdf($bytes, [
                'employee_id' => $employee->getKey(),
                'title' => 'Memorandum to Support Services: '.Str::limit($memo['subject'], 150, ''),
                'category' => 'memorandum',
                'visible_to_employee' => true,
                'access_level' => PersonnelDocument::ACCESS_SUPPORT_MESSAGE,
                'original_name' => $this->fileName($memo),
                'description' => 'Sent to the Support Services inbox.',
            ], $member, $locked);
            $locked->forceFill(['personnel_document_id' => $document->getKey()])->save();

            return $locked;
        });
    }

    public static function fromLine(Employee $employee): string
    {
        return trim(($employee->rank ? $employee->rank.' ' : '').$employee->name);
    }

    /** Whether this member can be offered "Email a copy to my City email". */
    public function canEmailCopy(User $member): bool
    {
        $address = (string) $member->employeeProfile?->city_email;

        return preg_match('/^[a-f0-9]{32}$/i', (string) config('communications.cloudflare.account_id', '')) === 1
            && (string) config('communications.cloudflare.api_token', '') !== ''
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && str_ends_with(strtolower($address), '@miamibeachfl.gov')
            && $member->email_verified_at !== null;
    }

    /**
     * Sends the PDF to the member's own verified City email. Returns only after the email provider accepted it;
     * any refusal is reported as not sent. Limited to a few sends per member per hour.
     */
    public function emailCopy(User $member, array $input): string
    {
        abort_unless($this->canEmailCopy($member), 409, 'Email is not available for your account. Download the PDF instead.');
        $memo = $this->validate($input);
        $key = 'memo-email:'.$member->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'You have emailed several memoranda recently. Download the PDF, or try again in an hour.']);
        }
        RateLimiter::hit($key, 3600);
        $address = (string) $member->employeeProfile->city_email;
        $bytes = $this->render($memo);
        // A fixed subject: the Email Center records the subject line, and the Hub keeps nothing of an external memo.
        $subject = 'Your MBFD memorandum (PDF attached)';
        app(CloudflareEmailDispatcher::class)->send(
            to: [$address],
            subject: $subject,
            text: 'Attached is the memorandum you prepared in MBFD Hub. Send it to the recipient as you normally would.',
            html: null,
            sourceType: 'personnel_memorandum_copy',
            actor: $member,
            attachments: [['filename' => $this->fileName($memo), 'type' => 'application/pdf', 'content' => base64_encode($bytes)]],
        );

        return $address;
    }
}

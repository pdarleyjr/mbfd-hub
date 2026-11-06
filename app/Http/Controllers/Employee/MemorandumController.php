<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PersonnelForms\MemorandumService;
use App\Support\HubTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/** Employee Portal API for the Interoffice Memorandum (routes/personnel-forms.php). */
final class MemorandumController extends Controller
{
    public function __construct(private readonly MemorandumService $memos) {}

    public function form(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $employee = $actor->employeeProfile;
        $address = (string) $employee?->city_email;

        return response()->json(['data' => [
            'from' => $employee ? MemorandumService::fromLine($employee) : '',
            'date' => HubTimezone::today()->format('Y-m-d'),
            'support_services_label' => MemorandumService::SUPPORT_SERVICES,
            'email_copy_available' => $this->memos->canEmailCopy($actor),
            'email_address_hint' => $address !== '' ? preg_replace('/^(.).*(@.*)$/', '$1•••$2', $address) : null,
        ]]);
    }

    /** The finished PDF, produced on request and not stored (any recipient outside Support Services). */
    public function pdf(Request $request): Response
    {
        $this->actor($request);
        $memo = $this->memos->validate($request->only(['to', 'from', 'date', 'subject', 'body']));
        $bytes = $this->memos->render($memo);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.addslashes($this->memos->fileName($memo)).'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function sendToSupportServices(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $request->validate(['client_key' => ['required', 'uuid']]);
        $message = $this->memos->sendToSupportServices($actor, $request->only(['date', 'subject', 'body']), (string) $request->input('client_key'));

        return response()->json(['data' => [
            'delivered' => true,
            'thread_url' => url('/employee/messages?thread='.$message->thread_id),
            'message' => 'Delivered to Support Services. You can follow the conversation in My Messages.',
        ]]);
    }

    public function emailCopy(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        try {
            $address = $this->memos->emailCopy($actor, $request->only(['to', 'from', 'date', 'subject', 'body']));
        } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'not_sent', 'message' => 'The email was not sent. Download the PDF instead; nothing was lost.'], 502);
        }

        return response()->json(['data' => ['sent' => true, 'message' => 'Sent to '.$address.'. The Hub kept no copy.']]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->employeeProfile !== null, 403);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Services\Personnel\PersonnelDocumentService;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PersonnelDocumentController extends Controller
{
    public function __construct(private readonly PersonnelDocumentService $documents) {}

    public function view(Request $request, PersonnelDocument $document): StreamedResponse
    {
        return $this->documents->stream($document, $this->actor($request), download: false);
    }

    public function download(Request $request, PersonnelDocument $document): StreamedResponse
    {
        return $this->documents->stream($document, $this->actor($request), download: true);
    }

    /** The memorandum PDF on a Support Services message: the conversation's member, or Support Services. */
    public function messageAttachment(Request $request, int $message, EmployeeLogisticsMessagingAccess $access): StreamedResponse
    {
        $actor = $this->actor($request);
        $record = EmployeeLogisticsMessage::query()->with(['thread:id,member_user_id', 'personnelDocument'])->find($message);
        $document = $record?->personnelDocument;
        abort_unless($actor instanceof User && $document instanceof PersonnelDocument, 404);
        $isMember = (int) $record->thread?->member_user_id === (int) $actor->getKey();
        abort_unless($isMember || $access->canViewLogisticsInbox($actor), 404);

        return $this->documents->streamAuthorized($document, $actor, download: $request->boolean('download'));
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user('web');

        return $user instanceof User ? $user : null;
    }
}

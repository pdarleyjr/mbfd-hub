<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Filament\Support\HasNavigationParent;
use App\Models\User;
use App\Services\MemberReplies\SubmissionConversationProjection;
use App\Services\MemberReplies\SubmissionConversationService;
use App\Support\MemberReplies\ReplySubject;
use App\Support\MemberReplies\SubmissionConversationAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The member's own station request, supply request or service ticket: every public update and the conversation,
 * with a reply box (docs/member-replies.md section 2.3). Only the member who submitted it can open it; for
 * anyone else it does not exist (404). Staff replies are notified with a link here.
 */
class MySubmissionConversation extends Page implements HasNavigationParent
{
    use ResolvesCanonicalEmployee;

    protected static ?string $slug = 'my-conversations/{type}/{id}';

    protected static string $view = 'filament.employee.pages.my-submission-conversation';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $type = '';

    #[Locked]
    public int $subjectId = 0;

    public string $reply = '';

    /** Server-issued idempotency key for the reply being sent; the browser cannot change it. */
    #[Locked]
    public ?string $replyKey = null;

    public function mount(string $type, int|string $id): void
    {
        $this->authenticatedEmployee();
        abort_unless(ReplySubject::isGenericThread($type), 404);
        $this->type = $type;
        $this->subjectId = (int) $this->authorizedSubject($type, (int) $id)->getKey();
    }

    public function sendReply(): void
    {
        $user = $this->member();
        $this->authorizedSubject($this->type, $this->subjectId);
        $this->validate(['reply' => ['required', 'string', 'max:'.SubmissionConversationService::MAX_BODY]], [], ['reply' => 'message']);
        if (! RateLimiter::attempt('submission-reply:'.$user->getKey(), 20, static fn (): bool => true, 60)) {
            throw ValidationException::withMessages(['reply' => 'You are sending messages too quickly. Wait a minute and try again.']);
        }

        // One key per message: a double tap or a retry stores the reply once.
        $this->replyKey ??= (string) Str::uuid();
        try {
            app(SubmissionConversationService::class)->postAsMember($user, $this->type, $this->subjectId, $this->reply, $this->replyKey);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['reply' => $exception->validator->errors()->first() ?: 'The message could not be sent.']);
        }
        $this->reply = '';
        $this->replyKey = null;
        Notification::make()->success()->title('Reply sent')->send();
    }

    public function getTitle(): string
    {
        return match ($this->type) {
            ReplySubject::APPARATUS_SERVICE_TICKET => 'Service request',
            ReplySubject::STATION_SUPPLY_REQUEST => 'Supply request',
            default => 'Station request',
        };
    }

    public static function navigationParentPath(): string
    {
        return parse_url(MyActivity::getUrl(panel: 'employee'), PHP_URL_PATH) ?: '/employee/my-activity';
    }

    public static function navigationParentLabel(): string
    {
        return 'Back to my activity';
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [MyActivity::getUrl(panel: 'employee') => 'My activity', $this->getTitle()];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        // Re-checked on every render, not only on mount.
        $subject = $this->authorizedSubject($this->type, $this->subjectId);

        return ['detail' => app(SubmissionConversationProjection::class)->memberView($this->member(), $this->type, $subject)];
    }

    private function authorizedSubject(string $type, int $id): Model
    {
        $user = $this->member();
        try {
            $subject = app(SubmissionConversationService::class)->subject($type, $id);
        } catch (ModelNotFoundException) {
            abort(404);
        }
        // Someone else's record is indistinguishable from one that does not exist.
        abort_unless(SubmissionConversationAccess::isParticipant($user, $type, $subject), 404);

        return $subject;
    }

    private function member(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

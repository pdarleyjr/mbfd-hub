<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Services\MemberReplies\SubmissionConversationService;
use App\Support\MemberReplies\SubmissionConversationAccess;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The staff side of the conversation on a station request, supply request or service ticket
 * (docs/member-replies.md section 2.3): one "Member conversation" action that shows the thread and sends a reply
 * the member sees. Internal notes keep using each record's own internal field; nothing typed here is internal.
 */
final class SubmissionConversationActions
{
    /** @param callable(): Model $record */
    public static function header(string $type, callable $record): PageAction
    {
        return self::configure(PageAction::make('member_conversation'), $type, $record, true);
    }

    public static function row(string $type): TableAction
    {
        // No per-row ledger lookups in a table: the label stays fixed and the list reads the awaiting state in bulk.
        return self::configure(TableAction::make('member_conversation'), $type, null, false);
    }

    /**
     * @template T of PageAction|TableAction
     *
     * @param  T  $action
     * @param  (callable(): Model)|null  $resolve
     * @return T
     */
    private static function configure(PageAction|TableAction $action, string $type, ?callable $resolve, bool $showAwaiting): PageAction|TableAction
    {
        $subject = static fn (?Model $record = null): Model => $resolve !== null ? $resolve() : $record ?? throw new \LogicException('No record.');

        return $action
            ->label(fn (?Model $record = null): string => $showAwaiting && self::awaiting($type, $subject($record)) ? 'Member replied' : 'Member conversation')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color(fn (?Model $record = null): string => $showAwaiting && self::awaiting($type, $subject($record)) ? 'warning' : 'gray')
            ->visible(fn (?Model $record = null): bool => SubmissionConversationAccess::canStaffRead(self::user(), $type, $subject($record)))
            ->modalHeading('Conversation with the member')
            ->modalDescription('Everything here is visible to the member who submitted it. Use the internal note on the record for anything staff-only.')
            ->modalSubmitActionLabel('Send reply')
            ->form(fn (?Model $record = null): array => [
                Forms\Components\Placeholder::make('thread')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => self::thread($type, $subject($record))),
                Forms\Components\Textarea::make('body')
                    ->label('Reply to the member')
                    ->rows(3)
                    ->maxLength(SubmissionConversationService::MAX_BODY)
                    ->required()
                    ->visible(fn (): bool => SubmissionConversationAccess::canStaffReply(self::user(), $type, $subject($record))),
            ])
            ->action(function (array $data, ?Model $record = null) use ($type, $subject): void {
                $model = $subject($record);
                if (! SubmissionConversationAccess::canStaffReply(self::user(), $type, $model) || blank($data['body'] ?? null)) {
                    return;
                }
                try {
                    app(SubmissionConversationService::class)->postAsStaff(self::user() ?? abort(403), $type, (int) $model->getKey(), (string) $data['body'], (string) Str::uuid());
                } catch (AuthorizationException) {
                    abort(403);
                }
                Notification::make()->title('Reply sent to the member')->success()->send();
            });
    }

    private static function awaiting(string $type, Model $subject): bool
    {
        return app(MemberReplyLedger::class)->isAwaiting($type, (int) $subject->getKey());
    }

    private static function thread(string $type, Model $subject): HtmlString
    {
        $user = self::user();
        if ($user === null) {
            return new HtmlString('');
        }
        try {
            $messages = app(SubmissionConversationService::class)->thread($user, $type, $subject);
        } catch (AuthorizationException) {
            return new HtmlString('');
        }

        return new HtmlString(view('filament.admin.submission-conversation', ['messages' => $messages])->render());
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}

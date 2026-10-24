<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestMessage;
use App\Models\User;
use Ramsey\Uuid\Uuid;

/**
 * Messages on a request: visible to the requester and to Captain 5 / EMS Admin. The other side
 * is notified once per message through the notification ledger.
 */
final class RequestMessageService
{
    public const BODY_MAX = 2000;

    /** A member can send this many messages in a row before Captain 5 has answered. No daily quota exists. */
    public const MAX_UNANSWERED = 5;

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly EmsAccess $access,
        private readonly EmsNotifier $notifier,
        private readonly RequestPresenter $presenter,
    ) {}

    /** @return array{result: array<string, mixed>, replayed: bool} */
    public function postAsMember(int $requestId, User $user, string $key, mixed $body): array
    {
        return $this->post($requestId, $user, $key, $body, member: true);
    }

    /** @return array{result: array<string, mixed>, replayed: bool} */
    public function postAsStaff(int $requestId, User $user, string $key, mixed $body): array
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }

        return $this->post($requestId, $user, $key, $body, member: false);
    }

    /** @return array{result: array<string, mixed>, replayed: bool} */
    private function post(int $requestId, User $user, string $key, mixed $body, bool $member): array
    {
        $data = EmsValidator::validate(['body' => is_string($body) ? $this->clean($body) : $body], ['body' => ['required', 'string', 'max:'.self::BODY_MAX]], [
            'body.required' => 'Write a message before you send.',
            'body.max' => 'A message can be at most '.self::BODY_MAX.' characters.',
        ]);

        return $this->idempotency->run($user, "ems.message.{$requestId}", $key, function () use ($requestId, $user, $key, $data, $member): array {
            $request = InventoryRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if ($request === null || ($member && (int) $request->submitted_by_user_id !== (int) $user->getKey())) {
                throw EmsException::notFound('request');
            }
            if ($request->status === InventoryRequest::CANCELLED) {
                throw EmsException::conflict('This request was cancelled, so messages are closed.', 'request_cancelled');
            }

            if ($member) {
                $lastStaffReply = (int) InventoryRequestMessage::query()->where('request_id', $request->getKey())
                    ->whereIn('author_context', [InventoryRequestEvent::CONTEXT_CAPTAIN5, InventoryRequestEvent::CONTEXT_EMS_ADMIN])->max('id');
                $unanswered = InventoryRequestMessage::query()->where('request_id', $request->getKey())
                    ->where('author_context', InventoryRequestEvent::CONTEXT_MEMBER)->where('id', '>', $lastStaffReply)->count();
                if ($unanswered >= self::MAX_UNANSWERED) {
                    throw new EmsException('Captain 5 has not answered yet. Your messages are saved; you can send more after they reply.', 'awaiting_reply', 429);
                }
            }

            $actor = $member ? EmsActor::member($user) : EmsActor::staff($user, $this->access);
            $message = InventoryRequestMessage::query()->create([
                'request_id' => $request->getKey(),
                'author_user_id' => $actor->userId, 'author_name' => $actor->name, 'author_rank' => $actor->rank,
                'author_context' => $actor->context,
                'body' => $data['body'],
                'client_message_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'ems-message:'.$user->getKey().':'.$request->getKey().':'.$key)->toString(),
            ]);
            $request->update(['last_activity_at' => now()]);
            $this->notifier->messagePosted($message);

            return $this->presenter->message($message) + ['request_id' => $request->uuid];
        });
    }

    /** Plain text only: drop control characters, normalise line breaks, trim. */
    private function clean(string $body): string
    {
        $body = (string) preg_replace('/[^\P{C}\n\t]+/u', '', str_replace("\r\n", "\n", $body));

        return trim($body);
    }
}

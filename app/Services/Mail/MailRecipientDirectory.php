<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\AccountStatus;
use App\Models\Mail\MailThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Address suggestions for the composer: active Hub accounts and people already seen in mail threads. Only
 * members who may write email can ask, and Bcc recipients are never a source (threads do not store them).
 */
final class MailRecipientDirectory
{
    private const MIN_QUERY = 2;

    private const MAX_QUERY = 80;

    public function __construct(
        private readonly MailAccess $access,
        private readonly AddressParser $addresses,
        private readonly MailComposer $composer,
    ) {}

    /** @return list<array{address: string, name: ?string, source: string}> */
    public function suggest(User $user, string $query, int $limit = 8): array
    {
        if (! $this->access->canView($user) || ! $this->access->canSend($user)) {
            throw MailActionRefused::forbidden();
        }
        $query = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query));
        $limit = max(1, min(20, $limit));
        if (mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }
        $query = mb_substr($query, 0, self::MAX_QUERY);
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $own = $this->composer->ownAddresses();
        $found = [];

        $members = User::query()->where('account_status', AccountStatus::Active->value)->whereNotNull('email')
            ->where(fn ($match) => $match->whereRaw("email {$like} ? ESCAPE '\\'", [$pattern])->orWhereRaw("name {$like} ? ESCAPE '\\'", [$pattern]))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'email']);
        foreach ($members as $member) {
            $address = $this->addresses->addresses($member->email)[0] ?? null;
            if ($address !== null && ! in_array($address, $own, true)) {
                $found[$address] ??= ['address' => $address, 'name' => $member->name !== '' ? $member->name : null, 'source' => 'member'];
            }
        }

        if (count($found) < $limit) {
            $recent = MailThread::query()->whereRaw("CAST(participants AS TEXT) {$like} ? ESCAPE '\\'", [$pattern])
                ->orderByDesc('last_message_at')->limit(40)->pluck('participants');
            foreach ($recent as $participants) {
                foreach ((array) $participants as $participant) {
                    $address = is_array($participant) ? ($participant['address'] ?? null) : null;
                    $name = is_array($participant) ? ($participant['name'] ?? null) : null;
                    if (! is_string($address) || in_array($address, $own, true) || isset($found[$address])) {
                        continue;
                    }
                    if (stripos($address.' '.(string) $name, $query) === false) {
                        continue;
                    }
                    $found[$address] = ['address' => $address, 'name' => is_string($name) ? $name : null, 'source' => 'correspondent'];
                }
            }
        }

        return array_slice(array_values($found), 0, $limit);
    }
}

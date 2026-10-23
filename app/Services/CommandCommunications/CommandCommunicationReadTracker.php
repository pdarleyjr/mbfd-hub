<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationChannelRead;
use App\Models\CommandCommunicationPost;
use App\Models\CommandCommunicationReadState;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Read state per channel. The 300 channel keeps its original one-row-per-user table so the
 * 300 page is untouched; the Captain 5 channel uses `command_communication_channel_reads`.
 * Unread = posts and replies by someone else, not removed, newer than the channel's marker.
 * An administrator's combined view counts a post once even when it is shown in both channels.
 */
final class CommandCommunicationReadTracker
{
    /** The posts' stored timestamp format (see the models' $dateFormat). */
    private const STORED_FORMAT = 'Y-m-d H:i:s.uP';

    public function lastReadAt(User $viewer, string $channel): ?CarbonInterface
    {
        if ($channel === CommandCommunicationPost::CHANNEL_COMMAND_300) {
            return CommandCommunicationReadState::query()->find($viewer->getKey())?->last_read_at;
        }

        return CommandCommunicationChannelRead::query()
            ->where('user_id', $viewer->getKey())->where('channel', $channel)->first()?->last_read_at;
    }

    /**
     * @param  list<string>  $channels
     * @return array<string, CarbonInterface|null> channel => marker (null = never read)
     */
    public function lastReadByChannel(User $viewer, array $channels): array
    {
        if (count($channels) < 2) {
            return array_combine($channels, array_map(fn (string $channel): ?CarbonInterface => $this->lastReadAt($viewer, $channel), $channels));
        }

        // One round trip for every channel (the Admin Panel badge runs on every admin page).
        $others = array_values(array_diff($channels, [CommandCommunicationPost::CHANNEL_COMMAND_300]));
        $query = DB::table('command_communication_channel_reads')
            ->select(['channel', 'last_read_at'])
            ->where('user_id', $viewer->getKey())
            ->whereIn('channel', $others === [] ? [''] : $others);
        if (in_array(CommandCommunicationPost::CHANNEL_COMMAND_300, $channels, true)) {
            $query->unionAll(DB::table('command_communication_read_states')
                ->selectRaw("cast('".CommandCommunicationPost::CHANNEL_COMMAND_300."' as varchar(20)) as channel, last_read_at") // code constant, not input
                ->where('user_id', $viewer->getKey()));
        }
        $found = [];
        foreach ($query->get() as $row) {
            $found[(string) $row->channel] = $row->last_read_at !== null ? Carbon::parse((string) $row->last_read_at) : null;
        }

        return array_combine($channels, array_map(static fn (string $channel): ?CarbonInterface => $found[$channel] ?? null, $channels));
    }

    /** @param  list<string>  $channels */
    public function markRead(User $viewer, array $channels): CarbonInterface
    {
        $now = now();
        foreach ($channels as $channel) {
            try {
                $this->write($viewer, $channel, $now);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the row first: just move its marker.
                $this->touch($viewer, $channel, $now);
            }
        }

        return $now;
    }

    /** @param  list<string>  $channels */
    public function unreadCount(User $viewer, array $channels): int
    {
        if ($channels === []) {
            return 0;
        }
        $markers = $this->lastReadByChannel($viewer, $channels);

        return CommandCommunicationPost::query()
            ->where('author_user_id', '!=', $viewer->getKey())
            ->whereNull('deleted_at')
            ->where(function (Builder $any) use ($markers): void {
                foreach ($markers as $channel => $lastRead) {
                    $any->orWhere(function (Builder $one) use ($channel, $lastRead): void {
                        $one->whereIn('audience', CommandCommunicationPost::audiencesFor($channel));
                        if ($lastRead !== null) {
                            // Compared at the stored precision: a bound Carbon would be cut to whole seconds.
                            $one->where('created_at', '>', $lastRead->format(self::STORED_FORMAT));
                        }
                    });
                }
            })
            ->count();
    }

    private function write(User $viewer, string $channel, CarbonInterface $now): void
    {
        if ($channel === CommandCommunicationPost::CHANNEL_COMMAND_300) {
            CommandCommunicationReadState::query()->updateOrCreate(['user_id' => $viewer->getKey()], ['last_read_at' => $now]);

            return;
        }
        CommandCommunicationChannelRead::query()->updateOrCreate(
            ['user_id' => $viewer->getKey(), 'channel' => $channel],
            ['last_read_at' => $now],
        );
    }

    private function touch(User $viewer, string $channel, CarbonInterface $now): void
    {
        if ($channel === CommandCommunicationPost::CHANNEL_COMMAND_300) {
            CommandCommunicationReadState::query()->whereKey($viewer->getKey())->update(['last_read_at' => $now]);

            return;
        }
        CommandCommunicationChannelRead::query()
            ->where('user_id', $viewer->getKey())->where('channel', $channel)
            ->update(['last_read_at' => $now->format(self::STORED_FORMAT)]);
    }
}

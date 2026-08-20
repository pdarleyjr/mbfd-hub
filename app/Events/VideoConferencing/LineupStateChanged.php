<?php

namespace App\Events\VideoConferencing;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LineupStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly string $state) {}

    public function broadcastOn(): Channel
    {
        return new Channel('video-conferencing.lineup');
    }

    public function broadcastAs(): string
    {
        return 'lineup.changed';
    }

    /** @return array{state: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return ['state' => $this->state, 'occurred_at' => now()->toIso8601String()];
    }
}

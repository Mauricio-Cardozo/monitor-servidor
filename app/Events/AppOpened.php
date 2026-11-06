<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AppOpened implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public string $app,
        public string $ip,
        public string $at,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('server.demo');
    }
}

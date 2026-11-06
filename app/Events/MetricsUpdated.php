<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MetricsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    // Lo que viaja por el WebSocket: las métricas de ServerMetrics
    public function __construct(public array $data) {}

    public function broadcastOn(): Channel
    {
        return new Channel('server.metrics');
    }
}

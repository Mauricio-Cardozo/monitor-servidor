<?php

namespace App\Console\Commands;

use App\Events\MetricsUpdated;
use App\Services\ServerMetrics;
use Illuminate\Console\Command;

class BroadcastMetrics extends Command
{
    protected $signature = 'metrics:broadcast';

    protected $description = 'Lee las métricas del servidor y las envía por WebSocket';

    public function handle(ServerMetrics $metrics): void
    {
        MetricsUpdated::dispatch($metrics->snapshot());

        $this->info('Métricas enviadas a las ' . now()->format('H:i:s'));
    }
}

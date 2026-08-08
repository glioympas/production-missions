<?php
// app/Console/Commands/OutboxRelayCommand.php
namespace App\Console\Commands;

use App\Outbox\Broker;
use App\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--max-events=10000 : Exit after publishing roughly this many events}
        {--max-time=3600 : Exit after this many seconds}
        {--max-memory=128 : Exit if memory usage exceeds this many MB}';

    protected $description = 'Publish pending outbox events (self-recycling for Supervisor)';

    public function handle(Broker $broker): int
    {
        $workerId  = (string) Str::uuid();
        $relay     = new OutboxRelay($broker, $workerId);

        $maxEvents = (int) $this->option('max-events');
        $maxTime   = (int) $this->option('max-time');
        $maxMemory = (int) $this->option('max-memory');

        $startedAt      = time();
        $eventsHandled  = 0;

        $this->info("Outbox relay started. Worker: {$workerId}");

        // Graceful shutdown on SIGTERM/SIGINT (Supervisor sends SIGTERM to stop).
        $shouldStop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
            pcntl_signal(SIGINT,  function () use (&$shouldStop) { $shouldStop = true; });
        }

        while (! $shouldStop) {
            $count = $relay->processBatch();
            $eventsHandled += $count;

            // --- recycle checks ---
            if ($eventsHandled >= $maxEvents) {
                $this->info("Reached max-events ({$maxEvents}). Exiting for restart.");
                break;
            }

            if ((time() - $startedAt) >= $maxTime) {
                $this->info("Reached max-time ({$maxTime}s). Exiting for restart.");
                break;
            }

            if ((memory_get_usage(true) / 1024 / 1024) >= $maxMemory) {
                $this->info("Reached max-memory ({$maxMemory}MB). Exiting for restart.");
                break;
            }

            if ($count === 0) {
                usleep(200_000); // idle nap
            }
        }

        $this->info("Shutting down cleanly. Handled {$eventsHandled} events.");
        return self::SUCCESS;
    }
}

<?php

namespace App\Outbox;

use Illuminate\Support\Facades\Log;

class LogBroker implements Broker
{
    public function publish(string $eventType, array $message): void
    {
        Log::info("PUBLISH {$eventType}", $message);
    }
}

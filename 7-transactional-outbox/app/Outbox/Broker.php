<?php

namespace App\Outbox;

interface Broker
{
    public function publish(string $eventType, array $message): void;
}

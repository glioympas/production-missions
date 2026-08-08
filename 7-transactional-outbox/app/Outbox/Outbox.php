<?php

namespace App\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Outbox
{
    /**
     * Record an event in the outbox.
     * MUST be called inside an existing DB transaction, alongside business writes.
     */
    public static function record(string $eventType, array $payload): void
    {
        DB::table('outbox')->insert([
            'id'           => (string) Str::uuid(),
            'event_type'   => $eventType,
            'payload'      => json_encode($payload),
            'attempts'     => 0,
            'locked_by'    => null,
            'locked_at'    => null,
            'created_at'   => now(),
            'published_at' => null,
            'failed_at'    => null,
        ]);
    }
}

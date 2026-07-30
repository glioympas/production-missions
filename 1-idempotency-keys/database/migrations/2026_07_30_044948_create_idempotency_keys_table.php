<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique();
            $table->string('request_hash');
            $table->unsignedSmallInteger('response_status')
                ->nullable();
            $table->json('response_body')
                ->nullable();
            $table->timestamps();

            $table->index('created_at'); // To clean old ones later
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

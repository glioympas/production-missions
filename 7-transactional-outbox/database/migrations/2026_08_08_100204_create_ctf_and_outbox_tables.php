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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('points')->default(0);
            $table->timestamps();
        });

        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('flag');
            $table->unsignedInteger('points')->default(0);
            $table->timestamps();
        });

        Schema::create('solves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('challenge_id')->constrained();
            $table->timestamps();

            // A team can only solve a challenge once.
            $table->unique(['team_id', 'challenge_id']);
        });

        Schema::create('outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('locked_by')->nullable();          // which worker claimed this row
            $table->timestamp('locked_at')->nullable();     // when claimed (for stale recovery)
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();  // null = not yet published
            $table->timestamp('failed_at')->nullable();     // set when dead-lettered

            // The claim UPDATE filters on these. Index makes it fast at scale.
            $table->index(['published_at', 'failed_at', 'locked_by', 'created_at'], 'outbox_claim_idx');
            // The sweeper filters on these.
            $table->index(['locked_by', 'locked_at'], 'outbox_sweep_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('solves');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('teams');
    }
};

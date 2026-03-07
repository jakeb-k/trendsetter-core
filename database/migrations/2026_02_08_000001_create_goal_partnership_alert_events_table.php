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
        Schema::create('goal_partnership_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partnership_id')->constrained('goal_partnerships')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('evaluation_source', [
                'log_submit',
                'scheduled_scan',
                'manual_debug',
            ]);
            $table->enum('selected_alert_type', [
                'consecutive_misses',
                'streak_broken',
                'inactivity',
                'behind_pace',
            ])->nullable();
            $table->json('candidate_types')->nullable();
            $table->string('outcome');
            $table->json('reason_codes')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->date('signal_date')->nullable();
            $table->json('snapshot_excerpt')->nullable();
            $table->uuid('notification_id')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['partnership_id', 'evaluated_at']);
            $table->index(['goal_id', 'evaluated_at']);
            $table->index(['recipient_user_id', 'evaluated_at']);
            $table->index(['outcome', 'evaluated_at']);
            $table->index('dedupe_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_partnership_alert_events');
    }
};

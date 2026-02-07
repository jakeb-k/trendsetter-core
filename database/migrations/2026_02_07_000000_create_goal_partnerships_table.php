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
        Schema::create('goal_partnerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->onDelete('cascade');
            $table->foreignId('initiator_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('partner_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['active', 'paused'])->default('active');
            $table->enum('role', ['cheerleader', 'drill_sergeant', 'silent'])->default('cheerleader');
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->unique('goal_id');
            $table->index(['initiator_user_id', 'status']);
            $table->index(['partner_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_partnerships');
    }
};

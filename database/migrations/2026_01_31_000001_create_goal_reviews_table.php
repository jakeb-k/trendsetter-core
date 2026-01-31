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
        Schema::create('goal_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('outcome', ['achieved', 'partially_achieved', 'not_achieved']);
            $table->json('feelings');
            $table->text('why');
            $table->text('wins');
            $table->text('obstacles');
            $table->text('lessons');
            $table->text('next_steps');
            $table->text('advice')->nullable();
            $table->json('stats_snapshot')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_reviews');
    }
};

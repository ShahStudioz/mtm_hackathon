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
        Schema::create('plan_states', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('initialized');
            $table->json('extracted_facts')->nullable();
            $table->json('venue_plan')->nullable();
            $table->json('schedule_plan')->nullable();
            $table->json('food_plan')->nullable();
            $table->json('judging_plan')->nullable();
            $table->json('budget_plan')->nullable();
            $table->json('validation_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_states');
    }
};

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
        Schema::create('ingested_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_state_id')->constrained()->cascadeOnDelete();
            $table->integer('batch_number')->default(1);
            $table->string('raw_index')->nullable();
            $table->string('sender')->nullable();
            $table->string('message_date')->nullable();
            $table->text('raw_content');
            $table->string('status')->default('accepted');
            $table->text('rejection_reason')->nullable();
            $table->json('affected_areas')->nullable();
            $table->timestamps();

            $table->index(['plan_state_id', 'batch_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingested_messages');
    }
};

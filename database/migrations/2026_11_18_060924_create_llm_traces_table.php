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
        Schema::create('llm_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_state_id')->constrained()->cascadeOnDelete();
            $table->integer('batch_number')->default(1);
            $table->integer('call_number');
            $table->string('model_name');
            $table->string('area');
            $table->longText('input_payload');
            $table->longText('output_payload');
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->timestamps();

            $table->index(['plan_state_id', 'batch_number']);
            $table->index(['plan_state_id', 'call_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_traces');
    }
};

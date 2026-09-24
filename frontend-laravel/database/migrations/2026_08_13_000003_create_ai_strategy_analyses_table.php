<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_strategy_analyses', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy');
            $table->string('provider_match_id');
            $table->string('model');
            $table->string('input_hash', 64);
            $table->string('decision');
            $table->string('methodology');
            $table->unsignedTinyInteger('confidence');
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['strategy', 'provider_match_id', 'model', 'input_hash'], 'ai_strategy_analysis_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_strategy_analyses');
    }
};

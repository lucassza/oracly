<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lay_odd_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('strategy');
            $table->string('match_key');
            $table->date('match_date');
            $table->timestamp('kickoff_at')->nullable();
            $table->string('home_team');
            $table->string('away_team');
            $table->string('competition')->nullable();
            $table->string('profile');
            $table->double('probability')->nullable();
            $table->double('fair_odd')->nullable();
            $table->double('offered_odd');
            $table->timestamps();

            $table->unique(['user_id', 'strategy', 'match_key'], 'lay_odd_quotes_user_strategy_match_unique');
            $table->index(['strategy', 'match_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lay_odd_quotes');
    }
};

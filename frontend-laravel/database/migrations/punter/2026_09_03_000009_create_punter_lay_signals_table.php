<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('lay_signals', function (Blueprint $table): void {
            $table->id();
            $table->text('match_key');
            $table->string('radar'); // 'lay_2x2' | 'lay_0x1'

            $table->timestampTz('kickoff_at')->nullable();
            $table->string('kickoff_raw')->nullable();

            $table->text('country')->nullable();
            $table->text('league')->nullable();
            $table->text('home_team')->nullable();
            $table->text('away_team')->nullable();

            $table->decimal('odd_home', 8, 3)->nullable();
            $table->decimal('odd_draw', 8, 3)->nullable();
            $table->decimal('odd_away', 8, 3)->nullable();

            $table->text('flashscore_url')->nullable();

            $table->smallInteger('ft_home')->nullable();
            $table->smallInteger('ft_away')->nullable();
            $table->smallInteger('ht_home')->nullable();
            $table->smallInteger('ht_away')->nullable();
            $table->string('ft_raw')->nullable();
            $table->string('ht_raw')->nullable();

            $table->boolean('settled')->default(false);
            $table->string('check_result')->nullable(); // 'green' | 'red'
            $table->text('diagnosis')->nullable();

            $table->boolean('flag_red_0x1_ht')->nullable();
            $table->boolean('flag_red_0x0_ht')->nullable();
            $table->boolean('flag_green_tranquilo_0x1')->nullable();
            $table->boolean('flag_green_0x0_ht_2x2')->nullable();
            $table->boolean('flag_btts_ht_2x2')->nullable();
            $table->boolean('flag_leitura')->nullable();

            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['match_key', 'radar'], 'lay_signals_match_radar_unique');
            $table->index('radar');
            $table->index('kickoff_at');
            $table->index('country');
            $table->index('league');
            $table->index('settled');
            $table->index('check_result');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('lay_signals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('panel_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('row_seq');
            $table->date('match_date')->nullable();
            $table->text('home_team')->nullable();
            $table->text('away_team')->nullable();
            $table->text('match_label')->nullable();
            $table->integer('rank_home')->nullable();
            $table->integer('rank_home_overall')->nullable();
            $table->integer('rank_away')->nullable();
            $table->integer('rank_away_overall')->nullable();
            $table->text('match_odds_tendency')->nullable();
            $table->text('corners_tendency')->nullable();
            $table->text('ht_tendency')->nullable();
            $table->text('goals_tendency')->nullable();
            $table->text('rout_recommendation')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('league')->nullable();
            $table->double('opening_odd_home')->nullable();
            $table->double('opening_odd_draw')->nullable();
            $table->double('opening_odd_away')->nullable();
            $table->double('opening_odd_over15_ft')->nullable();
            $table->double('opening_odd_over25_ft')->nullable();
            $table->double('opening_odd_btts')->nullable();
            $table->timestamps();

            $table->index('match_date');
            $table->index('league');
            $table->index('home_team');
            $table->index('away_team');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('panel_fixtures');
    }
};

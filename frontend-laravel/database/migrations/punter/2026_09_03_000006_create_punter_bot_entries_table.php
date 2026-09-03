<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('bot_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('block');
            $table->unsignedInteger('row_seq');
            $table->string('entry_id')->nullable();
            $table->date('entry_date')->nullable();
            $table->text('league')->nullable();
            $table->text('home_team')->nullable();
            $table->text('away_team')->nullable();
            $table->double('odd')->nullable();
            $table->text('market')->nullable();
            $table->string('result')->nullable();
            $table->string('game_id')->nullable();
            $table->string('filter_id')->nullable();
            $table->text('filter_name')->nullable();
            $table->timestamps();

            $table->index(['block', 'entry_date']);
            $table->index('result');
            $table->index('filter_id');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('bot_entries');
    }
};

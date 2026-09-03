<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        $columns = config('punter.sources.match_history.columns');

        Schema::connection('punter')->create('match_history', function (Blueprint $table) use ($columns): void {
            $table->id();
            $table->string('match_key');
            $table->unsignedInteger('row_seq');

            foreach ($columns as [$dbColumn, $type]) {
                match ($type) {
                    'date' => $table->date($dbColumn)->nullable(),
                    'int' => $table->integer($dbColumn)->nullable(),
                    'numeric' => $table->double($dbColumn)->nullable(),
                    'bool01' => $table->boolean($dbColumn)->nullable(),
                    default => $table->text($dbColumn)->nullable(),
                };
            }

            $table->timestamps();

            $table->index('data_hora_jogo');
            $table->index(['campeonato', 'data_hora_jogo']);
            $table->index('home_name');
            $table->index('away_name');
            $table->index('match_key');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('match_history');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('league_summary', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('row_seq');
            $table->text('league');
            $table->double('lay_fora')->nullable();
            $table->double('lay_casa')->nullable();
            $table->double('over')->nullable();
            $table->timestamps();

            $table->index('league');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('league_summary');
    }
};

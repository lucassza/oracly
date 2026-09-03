<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('bot_method_stats', function (Blueprint $table): void {
            $table->id();
            $table->string('block');
            $table->unsignedInteger('row_seq');
            $table->text('method');
            $table->integer('entries')->nullable();
            $table->integer('greens')->nullable();
            $table->integer('reds')->nullable();
            $table->double('win_rate')->nullable();
            $table->timestamps();

            $table->index('block');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('bot_method_stats');
    }
};

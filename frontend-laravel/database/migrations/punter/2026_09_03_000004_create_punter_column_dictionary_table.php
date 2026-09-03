<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('column_dictionary', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('row_seq');
            $table->text('column_label');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('column_label');
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('column_dictionary');
    }
};

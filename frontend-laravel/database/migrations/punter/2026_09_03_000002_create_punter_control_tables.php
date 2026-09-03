<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        Schema::connection('punter')->create('sheet_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('label');
            $table->string('spreadsheet_id');
            $table->text('spreadsheet_url');
            $table->string('sheet_name');
            $table->string('range')->nullable();
            $table->string('target');
            $table->json('extra')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('last_row_count')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['spreadsheet_id', 'sheet_name', 'range'], 'sheet_sources_location_unique');
        });

        Schema::connection('punter')->create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sheet_source_id')->constrained('sheet_sources')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status');
            $table->unsignedInteger('rows_read')->nullable();
            $table->unsignedInteger('rows_inserted')->nullable();
            $table->unsignedInteger('rows_updated')->nullable();
            $table->unsignedInteger('rows_skipped')->nullable();
            $table->unsignedBigInteger('bytes_downloaded')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['sheet_source_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('punter')->dropIfExists('import_runs');
        Schema::connection('punter')->dropIfExists('sheet_sources');
    }
};

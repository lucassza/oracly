<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'punter';

    public function up(): void
    {
        DB::connection('punter')->statement('CREATE SCHEMA IF NOT EXISTS punter');
    }

    public function down(): void
    {
        DB::connection('punter')->statement('DROP SCHEMA IF EXISTS punter CASCADE');
    }
};

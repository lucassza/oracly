<?php

namespace App\Console\Commands;

use Database\Seeders\PunterSheetSourcesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap do schema `punter` de ponta a ponta — necessário porque o próprio
 * `artisan migrate --database=punter` cria a tabela "migrations" ANTES de rodar
 * qualquer migration nossa, então o schema `punter` precisa existir antes disso
 * (senão cai em "no schema has been selected to create in"). Usa a conexão
 * `oracly` (mesmo Postgres, sem search_path=punter ainda) só para esse CREATE SCHEMA.
 */
class PunterInstall extends Command
{
    protected $signature = 'punter:install';

    protected $description = 'Cria o schema punter, roda as migrations e semeia punter.sheet_sources (idempotente)';

    public function handle(): int
    {
        $this->info('Criando schema punter (se não existir)...');
        DB::connection('oracly')->statement('CREATE SCHEMA IF NOT EXISTS punter');

        $this->info('Rodando migrations do schema punter...');
        Artisan::call('migrate', [
            '--database' => 'punter',
            '--path' => 'database/migrations/punter',
            '--force' => true,
        ], $this->getOutput());

        $this->info('Semeando punter.sheet_sources a partir de config/punter.php...');
        (new PunterSheetSourcesSeeder)->run();

        $this->info('Pronto. Rode "php artisan punter:sync" para importar os dados.');

        return self::SUCCESS;
    }
}

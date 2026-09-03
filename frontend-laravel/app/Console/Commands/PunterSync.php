<?php

namespace App\Console\Commands;

use App\Oracly\Punter\SheetImporter;
use App\Oracly\Support\PunterDb;
use Illuminate\Console\Command;
use Throwable;

class PunterSync extends Command
{
    protected $signature = 'punter:sync
        {--source= : Chave de uma única fonte (ver punter.sheet_sources.source_key); sem isso, sincroniza todas as ativas}
        {--dry-run : Baixa e valida o cabeçalho sem gravar nada}
        {--force : Ignora o hash do último sync e reimporta mesmo sem mudança}';

    protected $description = 'Sincroniza as planilhas Punter (Google Sheets) para o schema punter';

    public function handle(SheetImporter $importer): int
    {
        $requested = $this->option('source');
        $allSources = config('punter.sources');

        if ($requested !== null) {
            if (! isset($allSources[$requested])) {
                $this->error("Fonte desconhecida: {$requested}. Disponíveis: ".implode(', ', array_keys($allSources)));

                return self::FAILURE;
            }
            $keys = [$requested];
        } else {
            $active = PunterDb::connection()->table('sheet_sources')->where('is_active', true)->pluck('source_key')->all();
            $keys = array_values(array_intersect(array_keys($allSources), $active));
        }

        if ($keys === []) {
            $this->warn('Nenhuma fonte ativa para sincronizar.');

            return self::SUCCESS;
        }

        $failures = 0;
        $rows = [];

        foreach ($keys as $key) {
            $label = $allSources[$key]['label'];
            $this->line("Sincronizando <info>{$key}</info> ({$label})...");

            try {
                $result = $importer->run($key, force: (bool) $this->option('force'), dryRun: (bool) $this->option('dry-run'));

                $status = $result['skipped_unchanged'] ? 'inalterado' : 'ok';
                $rows[] = [
                    $key, $status, $result['rows_read'], $result['rows_inserted'],
                    number_format($result['bytes'] / 1024, 0).' KB',
                ];
            } catch (Throwable $e) {
                $failures++;
                $rows[] = [$key, 'ERRO', '-', '-', '-'];
                $this->error("  {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(['Fonte', 'Status', 'Linhas lidas', 'Linhas gravadas', 'Download'], $rows);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}

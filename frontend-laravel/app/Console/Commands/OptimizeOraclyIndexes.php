<?php

namespace App\Console\Commands;

use App\Oracly\Support\OraclyDb;
use Illuminate\Console\Command;

class OptimizeOraclyIndexes extends Command
{
    protected $signature = 'oracly:optimize-indexes';

    protected $description = 'Cria índices no Postgres Oracly (sokkerpro) sem apagar dados';

    public function handle(): int
    {
        $statements = [
            'CREATE INDEX IF NOT EXISTS match_snapshots_kickoff_at ON sokkerpro.match_snapshots (kickoff_at)',
            'CREATE INDEX IF NOT EXISTS match_snapshots_status_kickoff_desc ON sokkerpro.match_snapshots (status, kickoff_at DESC NULLS LAST)',
            'CREATE INDEX IF NOT EXISTS favorite_leagues_country ON sokkerpro.favorite_leagues (country)',
            'CREATE INDEX IF NOT EXISTS leagues_country ON sokkerpro.leagues (country)',
        ];

        $this->info('Aplicando índices (IF NOT EXISTS)…');
        foreach ($statements as $statement) {
            OraclyDb::connection()->statement($statement);
            $this->line(' ok: '.$statement);
        }

        OraclyDb::connection()->statement('ANALYZE sokkerpro.match_snapshots');
        $this->info('Pronto. Nenhum dado foi apagado.');

        $indexes = OraclyDb::connection()->select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'sokkerpro' AND tablename = 'match_snapshots' ORDER BY 1",
        );
        foreach ($indexes as $index) {
            $this->line(' - '.$index->indexname);
        }

        return self::SUCCESS;
    }
}

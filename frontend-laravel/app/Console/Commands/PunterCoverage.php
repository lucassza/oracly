<?php

namespace App\Console\Commands;

use App\Oracly\Support\PunterDb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Responde "dá para substituir o SokkerPRO pelas planilhas Punter?" com números,
 * comparando o universo de partidas de cada fonte. Cruza schemas via a conexão
 * `oracly` — punter e sokkerpro vivem no mesmo Postgres, só o search_path muda,
 * então um JOIN direto entre "punter"."match_history" e "sokkerpro"."match_snapshots"
 * funciona sem precisar juntar em PHP.
 */
class PunterCoverage extends Command
{
    protected $signature = 'punter:coverage';

    protected $description = 'Mede o quanto as bases Punter (Google Sheets) e SokkerPRO se sobrepõem, por volume/ligas/datas';

    public function handle(): int
    {
        $cross = DB::connection('oracly');

        $this->section('Volume e janela de datas por fonte');
        $this->table(['Fonte', 'Linhas', 'De', 'Até'], [
            $this->volumeRow($cross, 'punter.match_history', 'data_hora_jogo'),
            $this->volumeRow($cross, 'punter.lay_signals', 'kickoff_at'),
            $this->volumeRow($cross, 'punter.panel_fixtures', 'match_date'),
            $this->volumeRow($cross, 'sokkerpro.match_snapshots', 'kickoff_at', distinctBy: 'provider_match_id'),
        ]);

        $this->newLine();
        $this->section('Ligas distintas por fonte');
        $this->table(['Fonte', 'Ligas distintas'], [
            ['punter.match_history', $cross->table('punter.match_history')->distinct()->count('campeonato')],
            ['punter.lay_signals', $cross->table('punter.lay_signals')->distinct()->count('league')],
            ['punter.panel_fixtures', $cross->table('punter.panel_fixtures')->distinct()->count('league')],
            ['sokkerpro.match_snapshots (via leagues)', $cross->table('sokkerpro.leagues')->distinct()->count('competition')],
        ]);

        $this->newLine();
        $this->section('Sobreposição: lay_signals × match_history (mesmos times)');
        $laySignals = PunterDb::connection()->table('lay_signals')->count();
        $overlapHistory = $cross->selectOne('
            select count(distinct ls.id) n
            from punter.lay_signals ls
            join punter.match_history mh
              on lower(trim(ls.home_team)) = lower(trim(mh.home_name))
             and lower(trim(ls.away_team)) = lower(trim(mh.away_name))
        ')->n;
        $this->line(sprintf(
            '%d de %d sinais LAY (%.1f%%) têm os dois times aparecendo em algum jogo de punter.match_history.',
            $overlapHistory, $laySignals, $laySignals > 0 ? $overlapHistory / $laySignals * 100 : 0
        ));

        $this->newLine();
        $this->section('Sobreposição: lay_signals × sokkerpro.match_snapshots (mesmos times)');
        $overlapSokker = $cross->selectOne('
            select count(distinct ls.id) n
            from punter.lay_signals ls
            join sokkerpro.match_snapshots ms
              on lower(trim(ls.home_team)) = lower(trim(ms.home_team))
             and lower(trim(ls.away_team)) = lower(trim(ms.away_team))
        ')->n;
        $this->line(sprintf(
            '%d de %d sinais LAY (%.1f%%) têm os dois times aparecendo em algum snapshot do SokkerPRO.',
            $overlapSokker, $laySignals, $laySignals > 0 ? $overlapSokker / $laySignals * 100 : 0
        ));

        $this->newLine();
        $this->section('Sobreposição: match_history × sokkerpro.match_snapshots (mesmos times)');
        $matchHistoryTotal = $cross->table('punter.match_history')->count();
        $overlapBothWays = $cross->selectOne('
            select count(distinct mh.id) n
            from punter.match_history mh
            join sokkerpro.match_snapshots ms
              on lower(trim(mh.home_name)) = lower(trim(ms.home_team))
             and lower(trim(mh.away_name)) = lower(trim(ms.away_team))
        ')->n;
        $this->line(sprintf(
            '%d de %d partidas do histórico (%.1f%%) têm os dois times aparecendo em algum snapshot do SokkerPRO.',
            $overlapBothWays, $matchHistoryTotal, $matchHistoryTotal > 0 ? $overlapBothWays / $matchHistoryTotal * 100 : 0
        ));

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: int, 2: string, 3: string} */
    private function volumeRow($connection, string $table, string $dateColumn, ?string $distinctBy = null): array
    {
        $count = $distinctBy !== null
            ? $connection->table($table)->distinct()->count($distinctBy)
            : $connection->table($table)->count();

        $range = $connection->selectOne("select min({$dateColumn}) mn, max({$dateColumn}) mx from {$table}");

        return [$table, $count, (string) ($range->mn ?? '—'), (string) ($range->mx ?? '—')];
    }

    private function section(string $title): void
    {
        $this->info($title);
    }
}

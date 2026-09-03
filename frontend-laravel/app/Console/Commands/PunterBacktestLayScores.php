<?php

namespace App\Console\Commands;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use Illuminate\Console\Command;

/**
 * Mede a assertividade das 4 estratégias de placar exato (AgainstOneGoalStrategy e
 * subclasses — as mesmas usadas para o SokkerPRO, sem alteração) alimentadas com as
 * médias de gols do Punter em vez das do SokkerPRO: `media_gols_total_casa/visitante`
 * de punter.match_history. Confirma que a fonte de dados não muda o contrato das
 * estratégias — só a origem das features.
 */
class PunterBacktestLayScores extends Command
{
    protected $signature = 'punter:backtest-lay-scores
        {--limit=20000}
        {--top=10 : Quantas linhas mostrar nos recortes por liga}';

    protected $description = 'Mede a assertividade de AgainstOneGoalStrategy e subclasses usando as médias de gols do punter.match_history';

    public function handle(PunterMatchPickService $picks): int
    {
        $top = (int) $this->option('top');

        $strategies = [
            'against1' => ['label' => 'LAY 0x1/1x0', 'strategy' => new AgainstOneGoalStrategy],
            'against2' => ['label' => 'LAY 0x2/2x0', 'strategy' => new AgainstTwoGoalsStrategy],
            'against31' => ['label' => 'LAY 3x1/1x3', 'strategy' => new AgainstThreeOneStrategy],
            'against3' => ['label' => 'LAY 0x3/3x0', 'strategy' => new AgainstThreeGoalsStrategy],
        ];

        $history = array_values(array_filter(
            $picks->history((int) $this->option('limit')),
            fn (array $row): bool => $row['finalScore'] !== null && $row['homeGoalsAverage'] !== null && $row['awayGoalsAverage'] !== null
        ));

        if ($history === []) {
            $this->warn('Nenhuma partida apurada com placar e médias de gols disponíveis.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Amostra: %d partidas com placar e médias de gols do Punter.', count($history)));

        foreach ($strategies as $config) {
            $this->newLine();
            $this->info($config['label']);

            $bySub = [];
            $competitionRows = [];
            foreach ($history as $row) {
                $choice = $config['strategy']->choice($row);
                if ($choice === null) {
                    continue;
                }
                $hit = $row['finalScore'] !== $choice['score'];
                $bySub[$choice['score']][] = $hit;
                $competitionRows[] = ['competition' => $row['competition'], 'green' => $hit, 'score' => $choice['score']];
            }

            ksort($bySub);
            $table = [];
            foreach ($bySub as $score => $hits) {
                $summary = HitRateSummary::of(array_map(fn (bool $h): array => ['green' => $h], $hits), fn (array $r): bool => $r['green']);
                $table[] = [
                    'LAY '.str_replace('-', 'x', (string) $score),
                    $summary['entries'], $summary['greens'], $summary['reds'],
                    HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate']),
                ];
            }
            $this->table(['Sub-placar', 'Entradas', 'Green', 'Red', 'Assertividade', 'Breakeven lay'], $table);

            $this->breakdownByCompetition($competitionRows, $top);
        }

        $this->newLine();
        $this->line('Assertividade = placar final (resultado_ft) diferente do placar laydo.');

        return self::SUCCESS;
    }

    /** @param  list<array{competition: string, green: bool, score: string}>  $rows */
    private function breakdownByCompetition(array $rows, int $limit): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['competition']][] = ['green' => $row['green']];
        }

        $table = [];
        foreach ($groups as $competition => $entries) {
            $summary = HitRateSummary::of($entries, fn (array $r): bool => $r['green']);
            $table[$competition] = [$competition, $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate'])];
        }
        uasort($table, fn (array $a, array $b): int => $b[1] <=> $a[1]);

        $this->newLine();
        $this->line('Por liga:');
        $this->table(['Liga', 'Entradas', 'Green', 'Red', 'Assertividade'], array_slice(array_values($table), 0, $limit));
    }
}

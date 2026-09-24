<?php

namespace App\Console\Commands;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\PredictionService;
use App\Oracly\Support\HitRateSummary;
use Illuminate\Console\Command;

class BacktestLayStrategies extends Command
{
    protected $signature = 'oracly:backtest-lay
        {--limit=20000 : Quantidade de partidas encerradas lidas do histórico}
        {--favorites : Restringe às ligas favoritas, como faz a Lista LAY}
        {--profile=baseline : Perfil de sinal aplicado (baseline, balanced, strong)}';

    protected $description = 'Mede a assertividade histórica das estratégias de LAY por corte de O1.5 FT';

    /** @var array<int, string> */
    private const GATES = [0, 60, 65, 70, 75, 80, 85];

    public function handle(PredictionService $predictions, FavoritesService $favorites): int
    {
        $profile = (string) $this->option('profile');
        $strategies = [
            'against1' => ['label' => 'LAY 0x1/1x0', 'strategy' => app(AgainstOneGoalStrategy::class)],
            'against2' => ['label' => 'LAY 0x2/2x0', 'strategy' => app(AgainstTwoGoalsStrategy::class)],
            'against31' => ['label' => 'LAY 3x1/1x3', 'strategy' => app(AgainstThreeOneStrategy::class)],
            'against3' => ['label' => 'LAY 0x3/3x0', 'strategy' => app(AgainstThreeGoalsStrategy::class)],
        ];

        $rows = $predictions->history('over_15_ft', 0, (int) $this->option('limit'));
        if ($this->option('favorites')) {
            $leagues = $favorites->get()['leagues'];
            $rows = array_values(array_filter($rows, fn (array $row): bool => in_array(($row['country'] ?? '').'::'.($row['competition'] ?? ''), $leagues, true)));
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->score($row) !== null));
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['kickoffAt'] ?? ''), (string) ($b['kickoffAt'] ?? '')));

        if ($rows === []) {
            $this->warn('Nenhuma partida encerrada disponível para os filtros informados.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Amostra: %d partidas encerradas de %s a %s · perfil %s · ligas %s',
            count($rows),
            substr((string) $rows[0]['kickoffAt'], 0, 10),
            substr((string) $rows[count($rows) - 1]['kickoffAt'], 0, 10),
            $profile,
            $this->option('favorites') ? 'favoritas' : 'todas',
        ));

        foreach ($strategies as $config) {
            $this->newLine();
            $this->info($config['label']);
            $table = [];
            foreach (self::GATES as $gate) {
                $entries = array_values(array_filter($rows, function (array $row) use ($config, $gate, $profile): bool {
                    return (float) ($row['probability'] ?? 0) >= $gate
                        && $config['strategy']->choice($row) !== null
                        && $config['strategy']->matchesProfile($row, $profile);
                }));
                $overall = $this->summarize($entries, $config['strategy']);
                if ($overall['entries'] === 0) {
                    continue;
                }
                $development = $this->summarize(array_slice($entries, 0, (int) floor(count($entries) * 0.7)), $config['strategy']);
                $validation = $this->summarize(array_slice($entries, (int) floor(count($entries) * 0.7)), $config['strategy']);

                $table[] = [
                    $gate === 0 ? 'sem corte' : '≥ '.$gate.'%',
                    $overall['entries'],
                    $overall['greens'],
                    $overall['reds'],
                    $this->percent($overall['hitRate']),
                    HitRateSummary::layBreakeven($overall['hitRate']),
                    $this->percent($development['hitRate']).' / '.$this->percent($validation['hitRate']),
                ];
            }
            $this->table(['Corte O1.5', 'Entradas', 'Green', 'Red', 'Assertividade', 'Odd lay breakeven', 'Treino / validação'], $table);

            $bySub = [];
            foreach ($rows as $row) {
                $choice = $config['strategy']->choice($row);
                if ($choice === null || (float) ($row['probability'] ?? 0) < 75 || ! $config['strategy']->matchesProfile($row, $profile)) {
                    continue;
                }
                $bySub[$choice['score']][] = $row;
            }
            ksort($bySub);
            $subTable = [];
            foreach ($bySub as $score => $entries) {
                $summary = $this->summarize($entries, $config['strategy']);
                $subTable[] = [
                    'LAY '.str_replace('-', 'x', (string) $score),
                    $summary['entries'],
                    $summary['greens'],
                    $summary['reds'],
                    $this->percent($summary['hitRate']),
                    HitRateSummary::layBreakeven($summary['hitRate']),
                ];
            }
            $this->table(['Sub-placar (corte ≥ 75%)', 'Entradas', 'Green', 'Red', 'Assertividade', 'Odd lay breakeven'], $subTable);
        }

        $this->newLine();
        $this->line('Assertividade = placar final diferente do placar laydo. A odd de breakeven é 1/(1-assertividade): acima dela a estratégia perde dinheiro mesmo acertando.');
        $this->line('Treino / validação separa os 70% mais antigos dos 30% mais recentes da amostra já filtrada.');

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{entries: int, greens: int, reds: int, hitRate: ?float}
     */
    private function summarize(array $rows, AgainstOneGoalStrategy $strategy): array
    {
        return HitRateSummary::of(
            $rows,
            fn (array $row): bool => $this->score($row) !== $strategy->choice($row)['score'],
        );
    }

    /** @param array<string, mixed> $row */
    private function score(array $row): ?string
    {
        if (! is_numeric($row['homeScore'] ?? null) || ! is_numeric($row['awayScore'] ?? null)) {
            return null;
        }

        return sprintf('%d-%d', (int) $row['homeScore'], (int) $row['awayScore']);
    }

    private function percent(?float $value): string
    {
        return HitRateSummary::percent($value);
    }
}

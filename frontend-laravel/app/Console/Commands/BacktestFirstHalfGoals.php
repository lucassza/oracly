<?php

namespace App\Console\Commands;

use App\Oracly\Services\FavoritesService;
use App\Oracly\Services\FirstHalfGoalsHistoryService;
use App\Oracly\Services\FirstHalfGoalsStrategy;
use App\Oracly\Support\HitRateSummary;
use Illuminate\Console\Command;

class BacktestFirstHalfGoals extends Command
{
    protected $signature = 'oracly:backtest-over05ht
        {--limit=20000 : Quantidade de partidas encerradas lidas do histórico}
        {--favorites : Restringe às ligas favoritas}
        {--gate-profile=all : Perfil usado na tabela de cortes X7, ou "all" para varrer sem perfil}
        {--split=0.7 : Fração mais antiga usada como desenvolvimento}
        {--include-backfill : Inclui a trilha de backfill na tabela principal}
        {--no-cache : Relê o histórico ignorando o cache}';

    protected $description = 'Mede a assertividade histórica da estratégia Over 0.5 HT por perfil';

    /** @var list<int> */
    private const GATES = [0, 60, 65, 70, 75, 80, 85];

    public function handle(
        FirstHalfGoalsHistoryService $history,
        FirstHalfGoalsStrategy $strategy,
        FavoritesService $favorites,
    ): int {
        $split = (float) $this->option('split');
        $rows = $history->history((int) $this->option('limit'), ! $this->option('no-cache'));

        if ($this->option('favorites')) {
            $leagues = $favorites->get()['leagues'];
            $rows = array_values(array_filter($rows, fn (array $row): bool => in_array(
                ($row['country'] ?? '').'::'.($row['competition'] ?? ''), $leagues, true,
            )));
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['kickoffAt'] ?? ''), (string) ($b['kickoffAt'] ?? '')));

        $backfilled = array_values(array_filter($rows, fn (array $row): bool => ! empty($row['usedBackfilledFeatures'])));
        $clean = array_values(array_filter($rows, fn (array $row): bool => empty($row['usedBackfilledFeatures'])));
        $main = $this->option('include-backfill') ? $rows : $clean;

        if ($main === []) {
            $this->warn('Nenhuma partida encerrada disponível para os filtros informados.');

            return self::SUCCESS;
        }

        $days = count(array_unique(array_map(fn (array $row): string => substr((string) $row['kickoffAt'], 0, 10), $main)));

        $this->line(sprintf(
            'Amostra: %d entradas de %s a %s (%d dias) · ligas %s%s',
            count($main),
            substr((string) $main[0]['kickoffAt'], 0, 10),
            substr((string) $main[count($main) - 1]['kickoffAt'], 0, 10),
            $days,
            $this->option('favorites') ? 'favoritas' : 'todas',
            $this->option('include-backfill') ? ' · INCLUINDO backfill' : '',
        ));
        $this->line(sprintf(
            'Trilha backfill: %d entradas mantidas fora da tabela principal (features retroalimentadas).',
            count($backfilled),
        ));
        $this->line('Partidas sem placar de HT por time são descartadas na origem, não contadas como red.');

        $this->newLine();
        $this->info('Perfis');
        $this->table(
            ['Perfil', 'Entradas', '/dia', 'Green', 'Red', 'Assertividade', 'Odd back breakeven', 'Treino / validação'],
            array_values(array_filter(array_map(function (string $profile, string $label) use ($main, $strategy, $split, $days): ?array {
                $entries = $this->select($main, $strategy, $profile);
                if ($entries === []) {
                    return null;
                }
                $stats = HitRateSummary::temporal($entries, $this->isHit(), $split);

                return [
                    $label,
                    $stats['overall']['entries'],
                    $days > 0 ? number_format($stats['overall']['entries'] / $days, 1) : '—',
                    $stats['overall']['greens'],
                    $stats['overall']['reds'],
                    HitRateSummary::percent($stats['overall']['hitRate']),
                    HitRateSummary::backBreakeven($stats['overall']['hitRate']),
                    HitRateSummary::percent($stats['development']['hitRate']).' / '.HitRateSummary::percent($stats['validation']['hitRate']),
                ];
            }, array_keys(FirstHalfGoalsStrategy::PROFILES), FirstHalfGoalsStrategy::PROFILES))),
        );

        $gateProfile = (string) $this->option('gate-profile');
        $this->newLine();
        $this->info('Cortes X7 dentro do perfil '.$gateProfile);
        // "all" existe porque todo perfil declarado já carrega seu próprio piso de pred:
        // varrer cortes dentro de `baseline` nunca mostraria nada abaixo de 70%.
        $gateRows = $gateProfile === 'all' ? $main : $this->select($main, $strategy, $gateProfile);
        $this->table(
            ['Corte O0.5 HT', 'Entradas', 'Green', 'Red', 'Assertividade', 'Odd back breakeven', 'Treino / validação'],
            array_values(array_filter(array_map(function (int $gate) use ($gateRows, $split): ?array {
                $entries = array_values(array_filter($gateRows, fn (array $row): bool => (float) ($row['probability'] ?? 0) >= $gate));
                if ($entries === []) {
                    return null;
                }
                $stats = HitRateSummary::temporal($entries, $this->isHit(), $split);

                return [
                    $gate === 0 ? 'sem corte' : '≥ '.$gate.'%',
                    $stats['overall']['entries'],
                    $stats['overall']['greens'],
                    $stats['overall']['reds'],
                    HitRateSummary::percent($stats['overall']['hitRate']),
                    HitRateSummary::backBreakeven($stats['overall']['hitRate']),
                    HitRateSummary::percent($stats['development']['hitRate']).' / '.HitRateSummary::percent($stats['validation']['hitRate']),
                ];
            }, self::GATES))),
        );

        $base = array_values(array_filter($main, fn (array $row): bool => (float) ($row['probability'] ?? 0) >= 70));
        $baseRate = HitRateSummary::of($base, $this->isHit())['hitRate'];

        $this->newLine();
        $this->info('Sinais isolados dentro de X7 ≥ 70% (base '.HitRateSummary::percent($baseRate).')');
        $signals = [
            'média 1T ≥ 1.4' => fn (array $r): ?bool => ($v = $this->sum($r, 'firstHalfHomeGoalsAverage', 'firstHalfAwayGoalsAverage')) === null ? null : $v >= 1.4,
            'O1.5 HT ≥ 40' => fn (array $r): ?bool => is_numeric($r['over15HtProbability'] ?? null) ? (float) $r['over15HtProbability'] >= 40 : null,
            'escanteios 9.5 ≥ 55' => fn (array $r): ?bool => is_numeric($r['cornersProbability'] ?? null) ? (float) $r['cornersProbability'] >= 55 : null,
            'O2.5 FT ≥ 60' => fn (array $r): ?bool => is_numeric($r['over25Probability'] ?? null) ? (float) $r['over25Probability'] >= 60 : null,
            'média combinada ≥ 3.0' => fn (array $r): ?bool => is_numeric($r['combinedGoalsAverage'] ?? null) ? (float) $r['combinedGoalsAverage'] >= 3.0 : null,
            'BTTS ≥ 60' => fn (array $r): ?bool => is_numeric($r['bttsProbability'] ?? null) ? (float) $r['bttsProbability'] >= 60 : null,
            'selo Recomendado' => fn (array $r): ?bool => is_array($r['over05HtSeals'] ?? null) ? in_array('Recomendado', $r['over05HtSeals'], true) : null,
        ];
        $this->table(
            ['Sinal', 'Entradas', 'Assertividade', 'Lift (pp)', 'Cobertura'],
            array_map(function (string $label, callable $test) use ($base, $baseRate): array {
                $evaluable = array_values(array_filter($base, fn (array $row): bool => $test($row) !== null));
                $passing = array_values(array_filter($evaluable, fn (array $row): bool => $test($row) === true));
                $stats = HitRateSummary::of($passing, $this->isHit());

                return [
                    $label,
                    $stats['entries'],
                    HitRateSummary::percent($stats['hitRate']),
                    $stats['hitRate'] === null || $baseRate === null ? '—' : number_format($stats['hitRate'] - $baseRate, 1),
                    count($base) > 0 ? number_format(100 * count($evaluable) / count($base), 1).'%' : '—',
                ];
            }, array_keys($signals), $signals),
        );

        $this->newLine();
        $this->info('signalScore dentro de X7 ≥ 70%');
        $scoreTable = [];
        foreach (range(0, 6) as $score) {
            $entries = array_values(array_filter($base, fn (array $row): bool => $strategy->signalScore($row)['score'] === $score));
            if ($entries === []) {
                continue;
            }
            $stats = HitRateSummary::of($entries, $this->isHit());
            $available = array_map(fn (array $row): int => $strategy->signalScore($row)['available'], $entries);
            $scoreTable[] = [
                $score.'/6',
                $stats['entries'],
                HitRateSummary::percent($stats['hitRate']),
                number_format(array_sum($available) / count($available), 1),
            ];
        }
        $this->table(['Sinais confirmados', 'Entradas', 'Assertividade', 'Disponíveis (média)'], $scoreTable);

        if ($backfilled !== [] && ! $this->option('include-backfill')) {
            $this->newLine();
            $this->info('Trilha backfill (comparação — nunca somada à tabela principal)');
            $backfillTable = [];
            foreach (FirstHalfGoalsStrategy::PROFILES as $profile => $label) {
                $bf = HitRateSummary::of($this->select($backfilled, $strategy, $profile), $this->isHit());
                $cl = HitRateSummary::of($this->select($clean, $strategy, $profile), $this->isHit());
                if ($bf['entries'] === 0 && $cl['entries'] === 0) {
                    continue;
                }
                $delta = $bf['hitRate'] !== null && $cl['hitRate'] !== null ? $bf['hitRate'] - $cl['hitRate'] : null;
                if ($delta !== null && abs($delta) > 3) {
                    $this->warn(sprintf(
                        'Perfil %s: trilha backfill difere em %s pp da limpa. Features retroalimentadas (backfilledHalfTimeStatsAt / backfilledFromX7At) podem não refletir a forma do time antes do jogo.',
                        $label,
                        number_format($delta, 1),
                    ));
                }
                $backfillTable[] = [
                    $label,
                    $bf['entries'],
                    HitRateSummary::percent($bf['hitRate']),
                    $cl['entries'],
                    HitRateSummary::percent($cl['hitRate']),
                    $delta === null ? '—' : number_format($delta, 1),
                ];
            }
            $this->table(['Perfil', 'Entradas bf', 'Assertividade bf', 'Entradas limpas', 'Assertividade limpa', 'Δ pp'], $backfillTable);
        }

        $this->newLine();
        $this->line('Assertividade = pelo menos 1 gol no 1º tempo, conferido no placar de HT real por time.');
        $this->line('Odd back breakeven = 1/assertividade: abaixo dela a estratégia perde dinheiro mesmo acertando.');
        $this->line('Nenhum ROI é reportado: a odd disponível no histórico é a odd justa do modelo (oj), não preço de mercado.');
        $this->line(sprintf('Treino / validação separa os %d%% mais antigos dos %d%% mais recentes da amostra já filtrada.', (int) ($split * 100), (int) ((1 - $split) * 100)));

        return self::SUCCESS;
    }

    /** @return callable(array<string, mixed>): bool */
    private function isHit(): callable
    {
        return fn (array $row): bool => ! empty($row['hit']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function select(array $rows, FirstHalfGoalsStrategy $strategy, string $profile): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, $profile)));
    }

    /** @param array<string, mixed> $row */
    private function sum(array $row, string $first, string $second): ?float
    {
        return is_numeric($row[$first] ?? null) && is_numeric($row[$second] ?? null)
            ? (float) $row[$first] + (float) $row[$second]
            : null;
    }
}

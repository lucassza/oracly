<?php

namespace App\Console\Commands;

use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\PunterDb;
use Illuminate\Console\Command;

/**
 * Assertividade dos radares Lay 2x2 / Lay 0x1 direto de punter.lay_signals — já vem
 * rotulado (green/red apurado pelo Punter), então não precisa recalcular placar como
 * o BacktestLayStrategies faz sobre os snapshots do SokkerPRO.
 */
class PunterBacktestLay extends Command
{
    protected $signature = 'punter:backtest-lay
        {--radar=all : lay_2x2, lay_0x1 ou all}
        {--top=10 : Quantas linhas mostrar nos recortes por país/liga}';

    protected $description = 'Mede a assertividade histórica dos radares Punter (Lay 2x2 / Lay 0x1) por país, liga, odd e mês';

    public function handle(): int
    {
        $radarOption = (string) $this->option('radar');
        $top = (int) $this->option('top');

        $query = PunterDb::connection()->table('lay_signals')
            ->whereNotNull('check_result')
            ->select(['radar', 'country', 'league', 'odd_home', 'odd_away', 'check_result', 'kickoff_at']);

        if (in_array($radarOption, ['lay_2x2', 'lay_0x1'], true)) {
            $query->where('radar', $radarOption);
        }

        $rows = $query->get()->map(fn ($row): array => [
            'radar' => $row->radar,
            'country' => $row->country,
            'league' => $row->league,
            'favoriteOdd' => $row->odd_home !== null && $row->odd_away !== null
                ? min((float) $row->odd_home, (float) $row->odd_away)
                : null,
            'green' => $row->check_result === 'green',
            'month' => $row->kickoff_at !== null ? substr((string) $row->kickoff_at, 0, 7) : null,
        ])->all();

        if ($rows === []) {
            $this->warn('Nenhum sinal apurado (check_result preenchido) encontrado.');

            return self::SUCCESS;
        }

        $isHit = fn (array $row): bool => $row['green'];

        $this->info(sprintf('Amostra: %d sinais apurados (%s).', count($rows), $radarOption));

        foreach (['lay_2x2', 'lay_0x1'] as $radar) {
            if ($radarOption !== 'all' && $radarOption !== $radar) {
                continue;
            }
            $subset = array_values(array_filter($rows, fn (array $r): bool => $r['radar'] === $radar));
            if ($subset === []) {
                continue;
            }

            $this->newLine();
            $this->info(strtoupper($radar));
            $summary = HitRateSummary::of($subset, $isHit);
            $this->line(sprintf(
                'Geral: %d entradas · %d green · %d red · assertividade %s · breakeven lay %s',
                $summary['entries'], $summary['greens'], $summary['reds'],
                HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate'])
            ));

            $this->breakdown($subset, 'country', 'País', $top, $isHit);
            $this->breakdown($subset, 'league', 'Liga', $top, $isHit);
            $this->breakdownOddBucket($subset, $isHit);
            $this->breakdown($subset, 'month', 'Mês', 24, $isHit, sortByKey: true);
        }

        return self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function breakdown(array $rows, string $field, string $label, int $limit, callable $isHit, bool $sortByKey = false): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row[$field] ?? '—';
            $groups[$key][] = $row;
        }

        $table = [];
        foreach ($groups as $key => $groupRows) {
            $summary = HitRateSummary::of($groupRows, $isHit);
            $table[$key] = [$key, $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate'])];
        }

        if ($sortByKey) {
            ksort($table);
        } else {
            uasort($table, fn (array $a, array $b): int => $b[1] <=> $a[1]);
        }

        $this->newLine();
        $this->line("Por {$label}:");
        $this->table([$label, 'Entradas', 'Green', 'Red', 'Assertividade', 'Breakeven lay'], array_slice(array_values($table), 0, $limit));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function breakdownOddBucket(array $rows, callable $isHit): void
    {
        $buckets = ['< 1.30' => [], '1.30 – 1.50' => [], '1.50 – 2.00' => [], '2.00 – 3.00' => [], '≥ 3.00' => [], 'sem odd' => []];
        foreach ($rows as $row) {
            $odd = $row['favoriteOdd'];
            $key = match (true) {
                $odd === null => 'sem odd',
                $odd < 1.30 => '< 1.30',
                $odd < 1.50 => '1.30 – 1.50',
                $odd < 2.00 => '1.50 – 2.00',
                $odd < 3.00 => '2.00 – 3.00',
                default => '≥ 3.00',
            };
            $buckets[$key][] = $row;
        }

        $table = [];
        foreach ($buckets as $key => $groupRows) {
            if ($groupRows === []) {
                continue;
            }
            $summary = HitRateSummary::of($groupRows, $isHit);
            $table[] = [$key, $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate'])];
        }

        $this->newLine();
        $this->line('Por odd do favorito (menor entre casa/fora):');
        $this->table(['Faixa de odd', 'Entradas', 'Green', 'Red', 'Assertividade', 'Breakeven lay'], $table);
    }
}

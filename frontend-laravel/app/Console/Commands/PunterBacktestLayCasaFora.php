<?php

namespace App\Console\Commands;

use App\Oracly\Services\PunterLayCasaForaStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use Illuminate\Console\Command;

/**
 * Mede a assertividade do critério próprio de LAY Casa/Fora (odd do favorito, não a
 * recomendação da planilha) direto de punter.match_history — decide os cortes reais de
 * PunterLayCasaForaStrategy::PROFILES antes de usá-los na página.
 */
class PunterBacktestLayCasaFora extends Command
{
    protected $signature = 'punter:backtest-lay-casa-fora
        {--side=all : fora, casa ou all}
        {--limit=20000}
        {--top=10 : Quantas linhas mostrar nos recortes por liga}';

    protected $description = 'Mede a assertividade do critério próprio de LAY Casa/Fora (odd do favorito) sobre punter.match_history';

    public function handle(PunterMatchPickService $picks, PunterLayCasaForaStrategy $strategy): int
    {
        $sideOption = (string) $this->option('side');
        $top = (int) $this->option('top');

        $history = $picks->history((int) $this->option('limit'));
        $rows = [];
        foreach ($history as $row) {
            $choice = $strategy->choice($row);
            if ($choice === null) {
                continue;
            }
            $result = $strategy->result($row, $choice['side']);
            if ($result === null) {
                continue;
            }
            $rows[] = [
                'side' => $choice['side'],
                'favoriteOdd' => $choice['favoriteOdd'],
                'punterAgrees' => $choice['punterAgrees'],
                'competition' => $row['competition'],
                'month' => substr((string) $row['matchDate'], 0, 7),
                'green' => $result === 'green',
            ];
        }

        if ($rows === []) {
            $this->warn('Nenhuma partida apurada com odds válidas nos dois lados.');

            return self::SUCCESS;
        }

        $isHit = fn (array $row): bool => $row['green'];
        $this->info(sprintf('Amostra: %d partidas com favorito/azarão definidos por odd.', count($rows)));

        foreach (['fora', 'casa'] as $side) {
            if ($sideOption !== 'all' && $sideOption !== $side) {
                continue;
            }
            $subset = array_values(array_filter($rows, fn (array $r): bool => $r['side'] === $side));
            if ($subset === []) {
                continue;
            }

            $this->newLine();
            $this->info('LAY '.strtoupper($side === 'fora' ? 'FORA' : 'CASA'));
            $summary = HitRateSummary::of($subset, $isHit);
            $this->line(sprintf(
                'Geral (qualquer odd): %d entradas · %d green · %d red · assertividade %s · breakeven lay %s',
                $summary['entries'], $summary['greens'], $summary['reds'],
                HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate'])
            ));

            $this->breakdownOddBucket($subset, $isHit);
            $this->breakdownProfiles($subset, $isHit);
            $this->breakdownPunterAgreement($subset, $isHit);
            $this->breakdown($subset, 'competition', 'Liga', $top, $isHit);
            $this->breakdown($subset, 'month', 'Mês', 36, $isHit, sortByKey: true);
        }

        $this->newLine();
        $this->line('Assertividade = GouR Lay Casa/Fora do Punter para o lado azarão escolhido por odd (não a recomendação da planilha).');

        return self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function breakdownOddBucket(array $rows, callable $isHit): void
    {
        $buckets = ['< 1.30' => [], '1.30 – 1.50' => [], '1.50 – 1.80' => [], '1.80 – 2.20' => [], '2.20 – 3.00' => [], '≥ 3.00' => []];
        foreach ($rows as $row) {
            $odd = $row['favoriteOdd'];
            $key = match (true) {
                $odd < 1.30 => '< 1.30',
                $odd < 1.50 => '1.30 – 1.50',
                $odd < 1.80 => '1.50 – 1.80',
                $odd < 2.20 => '1.80 – 2.20',
                $odd < 3.00 => '2.20 – 3.00',
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
        $this->line('Por odd do favorito:');
        $this->table(['Faixa de odd', 'Entradas', 'Green', 'Red', 'Assertividade', 'Breakeven lay'], $table);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function breakdownProfiles(array $rows, callable $isHit): void
    {
        $table = [];
        foreach (PunterLayCasaForaStrategy::PROFILES as $profile => $cutoff) {
            $entries = array_values(array_filter($rows, fn (array $r): bool => $r['favoriteOdd'] < $cutoff));
            $summary = HitRateSummary::of($entries, $isHit);
            $table[] = [$profile." (odd < {$cutoff})", $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate'])];
        }

        $this->newLine();
        $this->line('Por perfil (PunterLayCasaForaStrategy::PROFILES):');
        $this->table(['Perfil', 'Entradas', 'Green', 'Red', 'Assertividade', 'Breakeven lay'], $table);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function breakdownPunterAgreement(array $rows, callable $isHit): void
    {
        $table = [];
        foreach ([['flag' => true, 'label' => 'Punter também recomendou'], ['flag' => false, 'label' => 'Punter não recomendou']] as ['flag' => $flag, 'label' => $label]) {
            $entries = array_values(array_filter($rows, fn (array $r): bool => $r['punterAgrees'] === $flag));
            $summary = HitRateSummary::of($entries, $isHit);
            $table[] = [$label, $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate']), HitRateSummary::layBreakeven($summary['hitRate'])];
        }

        $this->newLine();
        $this->line('Corte de odd sozinho vs. quando a recomendação do Punter concorda:');
        $this->table(['Recomendação do Punter', 'Entradas', 'Green', 'Red', 'Assertividade', 'Breakeven lay'], $table);
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
}

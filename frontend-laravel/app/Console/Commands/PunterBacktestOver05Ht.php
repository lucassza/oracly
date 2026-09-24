<?php

namespace App\Console\Commands;

use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Services\PunterOver05HtStrategy;
use App\Oracly\Support\HitRateSummary;
use Illuminate\Console\Command;

/**
 * Mede a assertividade de Over 0.5 HT sobre punter.match_history — pelo corte de odd
 * (`odds_1st_half_over05`, o sinal que realmente funciona) e, pra comparação, pela
 * recomendação do próprio Punter (`tendencia_over_ht`, mais fraca — é o único sinal
 * disponível pra jogos futuros, já que panel_fixtures não tem essa odd).
 */
class PunterBacktestOver05Ht extends Command
{
    protected $signature = 'punter:backtest-over05ht {--limit=60000} {--top=10}';

    protected $description = 'Mede a assertividade de Over 0.5 HT (odd de mercado vs. recomendação do Punter) sobre punter.match_history';

    public function handle(PunterMatchPickService $picks, PunterOver05HtStrategy $strategy): int
    {
        $top = (int) $this->option('top');
        $rows = array_values(array_filter(
            $picks->history((int) $this->option('limit')),
            fn (array $row): bool => $strategy->result($row) !== null,
        ));

        if ($rows === []) {
            $this->warn('Nenhuma partida apurada com resultado de Over 0.5 HT.');

            return self::SUCCESS;
        }

        $isHit = fn (array $row): bool => $strategy->result($row) === 'green';

        $this->info(sprintf('Amostra: %d partidas apuradas.', count($rows)));
        $summary = HitRateSummary::of($rows, $isHit);
        $this->line(sprintf(
            'Sem filtro: %d entradas · %d green · %d red · assertividade %s',
            $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate'])
        ));

        $this->newLine();
        $this->line('Por perfil de odd (odds_1st_half_over05 — o sinal que funciona):');
        $table = [];
        foreach (PunterOver05HtStrategy::ODD_PROFILES as $profile => $cutoff) {
            $entries = array_values(array_filter($rows, fn (array $r): bool => $strategy->matchesOddProfile($r, $profile)));
            $s = HitRateSummary::of($entries, $isHit);
            $table[] = [$profile." (odd < {$cutoff})", $s['entries'], $s['greens'], $s['reds'], HitRateSummary::percent($s['hitRate']), HitRateSummary::backBreakeven($s['hitRate'])];
        }
        $this->table(['Perfil', 'Entradas', 'Green', 'Red', 'Assertividade', 'Odd back breakeven'], $table);

        $this->newLine();
        $this->line('Recomendação do próprio Punter (tendencia_over_ht — único sinal disponível pra jogos futuros):');
        $recommended = array_values(array_filter($rows, fn (array $r): bool => $strategy->punterRecommends($r)));
        $s = HitRateSummary::of($recommended, $isHit);
        $this->table(['Fonte', 'Entradas', 'Green', 'Red', 'Assertividade'], [
            ['Punter recomendou', $s['entries'], $s['greens'], $s['reds'], HitRateSummary::percent($s['hitRate'])],
        ]);

        $this->breakdown($rows, 'competition', 'Liga', $top, $isHit);

        $this->newLine();
        $this->line('Assertividade = HTGoalCount > 0 (gour_over_05_ht). "Odd back breakeven" = 1/assertividade: abaixo dela a estratégia perde dinheiro mesmo acertando.');

        return self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function breakdown(array $rows, string $field, string $label, int $limit, callable $isHit): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row[$field] ?? '—';
            $groups[$key][] = $row;
        }

        $table = [];
        foreach ($groups as $key => $groupRows) {
            $summary = HitRateSummary::of($groupRows, $isHit);
            $table[$key] = [$key, $summary['entries'], $summary['greens'], $summary['reds'], HitRateSummary::percent($summary['hitRate'])];
        }
        uasort($table, fn (array $a, array $b): int => $b[1] <=> $a[1]);

        $this->newLine();
        $this->line("Por {$label}:");
        $this->table([$label, 'Entradas', 'Green', 'Red', 'Assertividade'], array_slice(array_values($table), 0, $limit));
    }
}

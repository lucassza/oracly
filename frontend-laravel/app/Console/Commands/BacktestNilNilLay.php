<?php

namespace App\Console\Commands;

use App\Oracly\Services\AgainstNilNilStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\LayPricing;
use Illuminate\Console\Command;

/**
 * Mede o LAY 0x0 selecionado pelo favorito (AgainstNilNilStrategy) contra o PREÇO de under 0,5.
 *
 * É o único lay de placar em que o histórico tem odd de mercado do próprio evento, então a
 * pergunta aqui não é só "acerta quanto", é "sai menos 0x0 do que o preço espera". O preço vem
 * de casa de aposta (margem ~6%) com a margem tirada proporcionalmente — que infla a chance do
 * lado caro. Por isso a tabela 2 mostra o controle (todos os jogos): a vantagem da seleção é a
 * diferença para ele, não o número cru.
 */
class BacktestNilNilLay extends Command
{
    protected $signature = 'punter:backtest-lay-0x0
        {--limit=60000 : Linhas lidas do match_history}
        {--split=0.7 : Fração mais antiga usada como desenvolvimento}
        {--top=15 : Ligas mostradas no breakdown}';

    protected $description = 'Mede o LAY 0x0 filtrado pelo favorito contra o preço de under 0,5 e a calibração do modelo da tela';

    public function handle(PunterMatchPickService $picks): int
    {
        $strategy = new AgainstNilNilStrategy();

        $rows = [];
        foreach ($picks->history((int) $this->option('limit')) as $row) {
            $favourite = $strategy->favouriteProbability($row);
            if ($favourite === null || $strategy->result($row) === null) {
                continue;
            }
            $rows[] = [...$row,
                'favourite' => $favourite,
                'market' => $strategy->marketProbability($row),
                'model' => $strategy->probability($row),
            ];
        }

        if ($rows === []) {
            $this->warn('Nenhuma partida apurada com odds de 1X2.');

            return self::SUCCESS;
        }

        usort($rows, fn (array $a, array $b): int => ($a['matchDate'] ?? '') <=> ($b['matchDate'] ?? ''));
        $cutDate = $rows[(int) floor(count($rows) * (float) $this->option('split'))]['matchDate'];

        $this->info(sprintf('Punter · %d partidas apuradas com 1X2 · %s a %s · validação a partir de %s.',
            count($rows), $rows[0]['matchDate'], $rows[count($rows) - 1]['matchDate'], $cutDate));

        $this->byProfile($rows, $strategy);
        $this->againstPrice($rows, $cutDate);
        $this->calibration($rows, $cutDate);
        $this->breakdown($rows, $strategy);

        $this->newLine();
        $this->line('O preço de under 0,5 do histórico é de casa de aposta. A vantagem só se confirma com a odd de lay da exchange.');

        return self::SUCCESS;
    }

    /** Tabela 1 — perfis com validação temporal e odd justa de coorte. */
    private function byProfile(array $rows, AgainstNilNilStrategy $strategy): void
    {
        $split = (float) $this->option('split');
        $isHit = fn (array $row): bool => $row['homeGoals'] + $row['awayGoals'] > 0;
        $table = [];
        foreach (['todos' => 'Todos os jogos'] + AgainstNilNilStrategy::PROFILES as $profile => $label) {
            $slice = $profile === 'todos' ? $rows : array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, $profile)));
            $s = HitRateSummary::temporal($slice, $isHit, $split);
            $fair = $s['overall']['hitRate'] === null ? null : LayPricing::fairOdd(100 - $s['overall']['hitRate']);
            $table[] = [
                $label, $s['overall']['entries'], sprintf('%.1f%%', $s['overall']['entries'] / count($rows) * 100),
                HitRateSummary::percent($s['overall']['hitRate']),
                HitRateSummary::percent($s['development']['hitRate']),
                HitRateSummary::percent($s['validation']['hitRate']),
                $fair === null ? '—' : number_format($fair, 2),
                $fair === null ? '—' : number_format(LayPricing::maxEntryOdd($fair), 2),
            ];
        }

        $this->newLine();
        $this->line(sprintf('1. Por perfil, com split temporal %.0f/%.0f:', $split * 100, (1 - $split) * 100));
        $this->table(['Perfil', 'Entradas', 'Fatia', 'Acerto', 'Desenvolvimento', 'Validação', 'Odd justa', 'Entre até'], $table);
    }

    /** Tabela 2 — a que importa: sai menos 0x0 do que o preço de under 0,5 espera? */
    private function againstPrice(array $rows, string $cutDate): void
    {
        // O controle vem primeiro: a margem proporcional infla o lado caro e faz até "todos os
        // jogos" parecer lucrativo. A vantagem da seleção é a razão dela sobre a do controle.
        $bands = [
            [0.0, 1.01, 'Todos (controle)'],
            [0.0, 0.5, 'Favorito < 50%'], [0.5, 0.6, '50–60%'], [0.6, 0.7, '60–70%'], [0.7, 0.8, '70–80%'], [0.8, 1.01, '≥ 80%'],
            [0.5, 1.01, 'Acumulado ≥ 50%'], [0.6, 1.01, 'Acumulado ≥ 60%'], [0.7, 1.01, 'Acumulado ≥ 70%'],
        ];
        $periods = ['treino' => fn (array $r): bool => $r['matchDate'] < $cutDate, 'validação' => fn (array $r): bool => $r['matchDate'] >= $cutDate];
        $control = [];
        $table = [];
        foreach ($bands as [$low, $high, $label]) {
            foreach ($periods as $period => $inPeriod) {
                $slice = array_values(array_filter($rows, fn (array $r): bool => $inPeriod($r) && $r['market'] !== null && $r['favourite'] >= $low && $r['favourite'] < $high));
                if ($slice === []) {
                    continue;
                }
                $ratio = $this->zeroZeroRate($slice) / (array_sum(array_column($slice, 'market')) / count($slice));
                $control[$period] ??= $ratio;
                $return = 0.0;
                foreach ($slice as $r) {
                    $return += LayPricing::realizedReturn($r['homeGoals'] + $r['awayGoals'] > 0, 100 / $r['market']);
                }
                $table[] = [$label, $period, count($slice),
                    sprintf('%.2f%%', $this->zeroZeroRate($slice)),
                    sprintf('%.2f%%', array_sum(array_column($slice, 'market')) / count($slice)),
                    sprintf('%.2f', $ratio),
                    sprintf('%.2f', $ratio / $control[$period]),
                    sprintf('%+.2f%%', $return / count($slice) * 100)];
            }
        }

        $this->newLine();
        $this->line('2. 0x0 observado contra o preço de under 0,5 (margem proporcional) e retorno laydando nesse preço com 5% de comissão.');
        $this->line('   Leia a coluna "Contra o controle": abaixo de 1,00 a faixa sai menos 0x0 do que o preço espera, além do viés da margem.');
        $this->table(['Favorito', 'Período', 'Entradas', '0x0 saiu', 'Preço espera', 'Saiu / esperado', 'Contra o controle', 'Retorno por responsab.'], $table);
    }

    /** Tabela 3 — a chance que a tela mostra por jogo acompanha o que sai? */
    private function calibration(array $rows, string $cutDate): void
    {
        $table = [];
        foreach (AgainstNilNilStrategy::PROFILE_CUTS as $profile => $cut) {
            foreach (['treino' => fn (array $r): bool => $r['matchDate'] < $cutDate, 'validação' => fn (array $r): bool => $r['matchDate'] >= $cutDate] as $period => $inPeriod) {
                $slice = array_values(array_filter($rows, fn (array $r): bool => $inPeriod($r) && $r['model'] !== null && $r['favourite'] >= $cut));
                if ($slice === []) {
                    continue;
                }
                usort($slice, fn (array $a, array $b): int => $a['model'] <=> $b['model']);
                $size = intdiv(count($slice), 3);
                $line = [$profile, $period, count($slice), sprintf('%.2f / %.2f%%', array_sum(array_column($slice, 'model')) / count($slice), $this->zeroZeroRate($slice))];
                for ($third = 0; $third < 3; $third++) {
                    $part = array_slice($slice, $third * $size, $third === 2 ? null : $size);
                    $line[] = sprintf('%.2f / %.2f%%', array_sum(array_column($part, 'model')) / count($part), $this->zeroZeroRate($part));
                }
                $table[] = $line;
            }
        }

        $this->newLine();
        $this->line('3. Calibração da chance por jogo (Poisson de 1X2 + over 2,5, recalibrado) — previsto / saiu:');
        $this->table(['Perfil', 'Período', 'Jogos com over 2,5', 'Total', 'Terço mais baixo', 'Terço do meio', 'Terço mais alto'], $table);
    }

    /** Tabela 4 — concentração por liga e por mês no perfil balanced. */
    private function breakdown(array $rows, AgainstNilNilStrategy $strategy): void
    {
        $slice = array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, 'balanced')));
        $isHit = fn (array $row): bool => $row['homeGoals'] + $row['awayGoals'] > 0;

        foreach ([['competition', 'Liga', (int) $this->option('top')], ['month', 'Mês', 60]] as [$field, $label, $max]) {
            $groups = [];
            foreach ($slice as $row) {
                $key = $field === 'month' ? substr((string) $row['matchDate'], 0, 7) : ($row['competition'] ?: '—');
                $groups[$key][] = $row;
            }
            $table = [];
            foreach ($groups as $key => $groupRows) {
                $s = HitRateSummary::of($groupRows, $isHit);
                $table[] = [$key, $s['entries'], sprintf('%.1f%%', $s['entries'] / max(1, count($slice)) * 100), HitRateSummary::percent($s['hitRate'])];
            }
            usort($table, fn (array $a, array $b): int => $field === 'month' ? ($a[0] <=> $b[0]) : ($b[1] <=> $a[1]));

            $this->newLine();
            $this->line("4. Perfil balanced por {$label}:");
            $this->table([$label, 'Entradas', 'Fatia', 'Acerto'], array_slice($table, 0, $max));
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function zeroZeroRate(array $rows): float
    {
        return count(array_filter($rows, fn (array $r): bool => $r['homeGoals'] + $r['awayGoals'] === 0)) / count($rows) * 100;
    }
}

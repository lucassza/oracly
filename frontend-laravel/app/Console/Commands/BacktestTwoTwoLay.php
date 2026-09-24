<?php

namespace App\Console\Commands;

use App\Oracly\Services\AgainstTwoTwoStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\PunterDb;
use Illuminate\Console\Command;

/**
 * Mede o LAY 2x2 selecionado pelo Poisson das odds de mercado (AgainstTwoTwoStrategy) sobre
 * punter.match_history, e compara com o radar Lay 2x2 que o próprio Punter publica em
 * punter.lay_signals.
 *
 * O cruzamento com o radar é por data de Brasília + nomes exatos de mandante e visitante, porque
 * as duas planilhas não compartilham match_key. Só uma fração casa — a tabela diz quanto.
 *
 * Nenhum retorno é reportado: não existe odd de mercado de placar exato em nenhuma fonte.
 */
class BacktestTwoTwoLay extends Command
{
    protected $signature = 'punter:backtest-lay-2x2
        {--limit=60000 : Linhas lidas do match_history}
        {--split=0.7 : Fração mais antiga usada como desenvolvimento}
        {--top=15 : Ligas mostradas no breakdown}';

    protected $description = 'Mede o LAY 2x2 filtrado pela P(2x2) implícita nas odds de 1X2 e over 2,5, contra o radar Lay 2x2 do Punter';

    public function handle(PunterMatchPickService $picks): int
    {
        $strategy = new AgainstTwoTwoStrategy();

        // Uma passada só pelo Poisson: as tabelas abaixo leem 'p22' em vez de refazer o ajuste.
        $rows = [];
        foreach ($picks->history((int) $this->option('limit')) as $row) {
            $probability = $strategy->scoreProbability($row);
            if ($probability !== null && $strategy->result($row) !== null) {
                $rows[] = [...$row, 'p22' => $probability];
            }
        }

        if ($rows === []) {
            $this->warn('Nenhuma partida apurada com odds de 1X2 e over 2,5.');

            return self::SUCCESS;
        }

        usort($rows, fn (array $a, array $b): int => ($a['matchDate'] ?? '') <=> ($b['matchDate'] ?? ''));

        $this->info(sprintf(
            'Punter · %d partidas apuradas com 1X2 e over 2,5 · %s a %s.',
            count($rows), $rows[0]['matchDate'], $rows[count($rows) - 1]['matchDate'],
        ));

        $isHit = fn (array $row): bool => $strategy->result($row) === 'green';

        $this->baseline($rows, $isHit);
        $this->deciles($rows, $isHit);
        $this->byProfile($rows, $isHit, $strategy);
        $this->overOnlyScale($rows, $isHit, $strategy);
        $this->bttsInsideProfile($rows, $isHit);
        $this->versusPunterRadar($rows, $isHit);
        $this->halfTime($rows, $isHit);
        $this->breakdown($rows, $isHit);

        $this->newLine();
        $this->line('Nenhum retorno é reportado: não existe odd de mercado de placar exato em nenhuma das fontes.');
        $this->line('A odd justa de lay vem da frequência observada do 2x2 no perfil. Entre só quando a odd oferecida for MENOR que ela.');

        return self::SUCCESS;
    }

    /** Tabela 1 — hipótese nula: laydar 2x2 em todo jogo. */
    private function baseline(array $rows, callable $isHit): void
    {
        $s = HitRateSummary::of($rows, $isHit);
        $fair = AgainstTwoTwoStrategy::fairLayOdd(100 - $s['hitRate']);

        $this->newLine();
        $this->line('1. Linha de base, sem filtro:');
        $this->table(['Entradas', 'Green', 'Red', 'Assertividade', 'Odd justa de lay'], [[
            $s['entries'], $s['greens'], $s['reds'], HitRateSummary::percent($s['hitRate']), number_format($fair, 2),
        ]]);
    }

    /** Tabela 2 — o modelo ordena? Previsto contra observado por decil. */
    private function deciles(array $rows, callable $isHit): void
    {
        $sorted = $rows;
        usort($sorted, fn (array $a, array $b): int => $a['p22'] <=> $b['p22']);
        $size = intdiv(count($sorted), 10);

        $table = [];
        for ($decile = 0; $decile < 10; $decile++) {
            $slice = array_slice($sorted, $decile * $size, $decile === 9 ? null : $size);
            $s = HitRateSummary::of($slice, $isHit);
            $table[] = [
                'D'.($decile + 1),
                sprintf('%.2f – %.2f%%', $slice[0]['p22'], $slice[count($slice) - 1]['p22']),
                $s['entries'],
                sprintf('%.2f%%', array_sum(array_column($slice, 'p22')) / count($slice)),
                sprintf('%.2f%%', 100 - $s['hitRate']),
                HitRateSummary::percent($s['hitRate']),
            ];
        }

        $this->newLine();
        $this->line('2. Por decil de P(2x2) do modelo — previsto contra observado:');
        $this->table(['Decil', 'Faixa', 'Entradas', '2x2 previsto', '2x2 observado', 'Assertividade lay'], $table);
    }

    /** Tabela 3 — perfis com validação temporal e odd justa. */
    private function byProfile(array $rows, callable $isHit, AgainstTwoTwoStrategy $strategy): void
    {
        $split = (float) $this->option('split');
        $table = [];
        foreach (AgainstTwoTwoStrategy::PROFILES as $profile => $label) {
            $slice = $this->profileSlice($rows, $profile);
            $s = HitRateSummary::temporal($slice, $isHit, $split);
            $fair = $s['overall']['hitRate'] === null ? null : AgainstTwoTwoStrategy::fairLayOdd(100 - $s['overall']['hitRate']);
            $table[] = [
                $profile, $label, $s['overall']['entries'],
                sprintf('%.1f%%', $s['overall']['entries'] / count($rows) * 100),
                HitRateSummary::percent($s['overall']['hitRate']),
                HitRateSummary::percent($s['development']['hitRate']),
                HitRateSummary::percent($s['validation']['hitRate']),
                $fair === null ? '—' : number_format($fair, 2),
            ];
        }

        $this->newLine();
        $this->line(sprintf('3. Por perfil, com split temporal %.0f/%.0f:', $split * 100, (1 - $split) * 100));
        $this->table(['Perfil', 'Corte', 'Entradas', 'Fatia', 'Total', 'Desenvolvimento', 'Validação', 'Odd justa de lay'], $table);
    }

    /**
     * Tabela 4 — a lista do dia só tem o lado over. Sem o under a margem vem da média
     * (MarketPoisson::OVER25_OVERROUND); se a seleção mudar muito aqui, a lista diária não reproduz o backtest.
     */
    private function overOnlyScale(array $rows, callable $isHit, AgainstTwoTwoStrategy $strategy): void
    {
        $overOnly = array_map(fn (array $row): array => [...$row, 'oddUnder25' => null], $rows);

        $table = [];
        foreach (array_keys(AgainstTwoTwoStrategy::PROFILES) as $profile) {
            $both = $this->profileSlice($rows, $profile);
            $single = array_values(array_filter($overOnly, fn (array $row): bool => $strategy->matchesProfile($row, $profile)));
            $table[] = [
                $profile,
                count($both), HitRateSummary::percent(HitRateSummary::of($both, $isHit)['hitRate']),
                count($single), HitRateSummary::percent(HitRateSummary::of($single, $isHit)['hitRate']),
            ];
        }

        $this->newLine();
        $this->line('4. Margem do over 2,5 tirada da linha (histórico) contra a média (como na lista do dia):');
        $this->table(['Perfil', 'Entradas over+under', 'Assertividade', 'Entradas só over', 'Assertividade'], $table);
    }

    /** Tabela 5 — BTTS dentro do perfil. Se separasse, deveria virar filtro. */
    private function bttsInsideProfile(array $rows, callable $isHit): void
    {
        $bands = [[0.0, 50.0], [50.0, 55.0], [55.0, 60.0], [60.0, null]];
        $table = [];
        foreach (array_keys(AgainstTwoTwoStrategy::PROFILES) as $profile) {
            $line = [$profile];
            foreach ($bands as [$low, $high]) {
                $slice = array_values(array_filter($this->profileSlice($rows, $profile), function (array $row) use ($low, $high): bool {
                    $yes = $row['oddBttsYes'] ?? null;
                    $btts = is_numeric($yes) && $yes > 1 ? 100 / $yes : null;

                    return $btts !== null && $btts >= $low && ($high === null || $btts < $high);
                }));
                $s = HitRateSummary::of($slice, $isHit);
                $line[] = sprintf('%s (%d)', HitRateSummary::percent($s['hitRate']), $s['entries']);
            }
            $table[] = $line;
        }

        $this->newLine();
        $this->line('5. BTTS sim (1/odd, cru) dentro de cada perfil — plano quer dizer que o modelo já absorveu:');
        $this->table(['Perfil', 'BTTS < 50%', '50-55%', '55-60%', '≥ 60%'], $table);
    }

    /** Tabela 6 — contra o radar Lay 2x2 do Punter, nos jogos que casam nas duas bases. */
    private function versusPunterRadar(array $rows, callable $isHit): void
    {
        $signals = PunterDb::connection()->table('lay_signals')
            ->where('radar', 'lay_2x2')
            ->whereNotNull('check_result')
            ->selectRaw("(kickoff_at at time zone 'America/Sao_Paulo')::date as match_date, home_team, away_team")
            ->get();

        $radar = [];
        $firstSignal = null;
        foreach ($signals as $signal) {
            $firstSignal = $firstSignal === null ? (string) $signal->match_date : min($firstSignal, (string) $signal->match_date);
            $radar[$this->matchIdentity((string) $signal->match_date, (string) $signal->home_team, (string) $signal->away_team)] = true;
        }

        $window = array_values(array_filter($rows, fn (array $row): bool => $firstSignal !== null && $row['matchDate'] >= $firstSignal));
        $flagged = array_values(array_filter($window, fn (array $row): bool => isset($radar[$this->matchIdentity($row['matchDate'], $row['homeTeam'], $row['awayTeam'])])));

        $table = [];
        foreach ([
            ['Todos os jogos do período do radar', $window],
            ['Radar Punter (jogos casados)', $flagged],
            ['Radar Punter ∩ balanced', array_values(array_filter($flagged, fn (array $row): bool => $row['p22'] < AgainstTwoTwoStrategy::PROFILE_CUTS['balanced']))],
            ['Radar Punter fora do balanced', array_values(array_filter($flagged, fn (array $row): bool => $row['p22'] >= AgainstTwoTwoStrategy::PROFILE_CUTS['balanced']))],
            ['balanced no período do radar', array_values(array_filter($window, fn (array $row): bool => $row['p22'] < AgainstTwoTwoStrategy::PROFILE_CUTS['balanced']))],
            ['strong no período do radar', array_values(array_filter($window, fn (array $row): bool => $row['p22'] < AgainstTwoTwoStrategy::PROFILE_CUTS['strong']))],
        ] as [$label, $slice]) {
            $s = HitRateSummary::of($slice, $isHit);
            $mean = $slice === [] ? null : array_sum(array_column($slice, 'p22')) / count($slice);
            $table[] = [$label, $s['entries'], HitRateSummary::percent($s['hitRate']), $mean === null ? '—' : sprintf('%.2f%%', $mean)];
        }

        $this->newLine();
        $this->line(sprintf('6. Contra o radar Lay 2x2 do Punter — %d sinais apurados, %d casados por data + times:', count($radar), count($flagged)));
        $this->table(['Recorte', 'Entradas', 'Assertividade', 'P(2x2) médio'], $table);
    }

    /** Tabela 7 — o que o placar do intervalo diz, dentro do perfil balanced. Leitura para quem segura a entrada ao vivo. */
    private function halfTime(array $rows, callable $isHit): void
    {
        $groups = [];
        foreach ($this->profileSlice($rows, 'balanced') as $row) {
            if ($row['htScore'] !== null) {
                $groups[$row['htScore']][] = $row;
            }
        }
        uasort($groups, fn (array $a, array $b): int => count($b) <=> count($a));

        $table = [];
        foreach (array_slice($groups, 0, 10, true) as $score => $slice) {
            $s = HitRateSummary::of($slice, $isHit);
            $table[] = [str_replace('-', 'x', (string) $score), $s['entries'], HitRateSummary::percent($s['hitRate'])];
        }

        $this->newLine();
        $this->line('7. Perfil balanced por placar do intervalo:');
        $this->table(['Intervalo', 'Entradas', 'Assertividade'], $table);
    }

    /** Tabela 8 — concentração por liga e por mês. */
    private function breakdown(array $rows, callable $isHit): void
    {
        $slice = $this->profileSlice($rows, 'balanced');

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
            $this->line("8. Perfil balanced por {$label}:");
            $this->table([$label, 'Entradas', 'Fatia', 'Assertividade'], array_slice($table, 0, $max));
        }
    }

    /** @return list<array<string, mixed>> */
    private function profileSlice(array $rows, string $profile): array
    {
        $cut = AgainstTwoTwoStrategy::PROFILE_CUTS[$profile];

        return array_values(array_filter($rows, fn (array $row): bool => $row['p22'] < $cut));
    }

    private function matchIdentity(string $date, string $home, string $away): string
    {
        return substr($date, 0, 10).'|'.mb_strtolower(trim($home)).'|'.mb_strtolower(trim($away));
    }
}

<?php

namespace App\Console\Commands;

use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\LayPricing;
use App\Oracly\Support\MarketPoisson;
use Illuminate\Console\Command;

/**
 * Pesquisa exploratória de mercados novos no punter.match_history, antes de virar estratégia.
 *
 * Quatro ideias, cada uma com hipótese nula, faixas do sinal e desenvolvimento × validação:
 *
 *   1. LAY empate no 1º tempo — Poisson do HT: total pelo over 0,5 HT, divisão pelo 1X2.
 *      Não há odd de 1X2 do intervalo no histórico: só acerto e odd justa, sem ROI real.
 *   2. Over / under 2,5 com edge de liga acumulado (mesma ideia do Over15ValueStrategy).
 *   3. Incoerência entre mercados: o over 2,5 que o over 1,5 implica contra o preço do over 2,5.
 *   4. Match odds: LAY empate FT com favorito forte e BACK favorito com edge de liga.
 *
 * Retorno de back por unidade apostada; de lay por unidade de responsabilidade (LayPricing).
 * A odd "justa" é a da casa com a margem tirada proporcionalmente — proxy da exchange.
 * Edge de liga só com jogos ANTERIORES ao jogo, para não vazar o resultado para o filtro.
 *
 * RESULTADO (44.937 jogos 2023-01 a 2026-09, validação a partir de 2025-07-01):
 *
 *   1. Empate HT sai em 41,8% (0x0 30,3%, 1x1 10,6%). Fav ≥ 75% e over 0,5 HT < 1,25: lay acerta
 *      72,9% dev / 76,8% val (n=1.028), odd justa do lay 3,91. Sem preço de HT, fica em aberto.
 *   2. Over/under 2,5 por edge de liga: nenhum perfil positivo na validação. Descartado.
 *   3. Under 2,5 com gap +6 a +15pp: ROI justa +2,95% dev (n=1.290) / +6,78% val (n=213), positivo
 *      em todos os anos e ainda +4,71% a −2%. Gap ≥ +15pp é odd errada (114 jogos) e fica fora.
 *   4a. LAY empate FT com fav ≥ 75% e over 2,5 ≥ 65%: +3,92% dev / +2,76% val (n=894), +2,13% com
 *       a odd 5% acima da justa. A margem proporcional pode favorecer o lay da odd longa.
 *   4b. Back favorito: o controle "justa < 1,60" sem liga dá o mesmo retorno. O ganho é viés da
 *       retirada proporcional da margem, não da liga, e à odd da casa perde. Descartado.
 */
class BacktestNewMarkets extends Command
{
    protected $signature = 'punter:backtest-new-markets
        {--limit=60000 : Linhas lidas do match_history}
        {--cut=2025-07-01 : Data que separa desenvolvimento (antes) de validação (a partir)}
        {--only= : Roda só uma seção: ht-draw, ou25, cross, match-odds}';

    protected $description = 'Explora LAY empate HT, over/under 2.5, incoerência entre mercados e match odds';

    private const COMMISSION = 0.065;

    private const MIN_LEAGUE_ENTRIES = 100;

    private string $cut;

    public function handle(PunterMatchPickService $picks): int
    {
        $rows = array_values(array_filter(
            $picks->history((int) $this->option('limit')),
            fn (array $row): bool => is_numeric($row['homeGoals'] ?? null) && is_numeric($row['awayGoals'] ?? null),
        ));
        if ($rows === []) {
            $this->warn('Nenhum jogo apurado.');

            return self::SUCCESS;
        }
        // match_history só tem data: o desempate pela chave deixa o edge acumulado da liga igual entre execuções.
        usort($rows, fn (array $a, array $b): int => [$a['matchDate'] ?? '', $a['matchKey']] <=> [$b['matchDate'] ?? '', $b['matchKey']]);
        $this->cut = (string) $this->option('cut');

        $this->info(sprintf('%d jogos apurados · %s a %s · validação a partir de %s · comissão %.1f%%.',
            count($rows), $rows[0]['matchDate'], $rows[count($rows) - 1]['matchDate'], $this->cut, self::COMMISSION * 100));

        $only = (string) $this->option('only');
        if ($only === '' || $only === 'ht-draw') {
            $this->htDraw($rows);
        }
        if ($only === '' || $only === 'ou25') {
            $this->overUnder25($rows);
        }
        if ($only === '' || $only === 'cross') {
            $this->crossMarket($rows);
        }
        if ($only === '' || $only === 'match-odds') {
            $this->matchOdds($rows);
        }

        $this->newLine();
        $this->line('Odds de FECHAMENTO de casa de aposta. A lista do dia usa abertura; o que decide é a odd da exchange na entrada.');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------------------------
    // 1. LAY empate no 1º tempo
    // ---------------------------------------------------------------------------------------

    private function htDraw(array $rows): void
    {
        $data = [];
        foreach ($rows as $row) {
            $ht = $this->htGoals($row);
            $model = $this->htModel($row);
            if ($ht === null || $model === null) {
                continue;
            }
            $data[] = [...$row, '_ht' => $ht, '_pDraw' => $model['draw'], '_pFavHt' => $model['favourite'],
                '_lambdaHt' => $model['lambda'], '_fav' => MarketPoisson::favouriteProbability($row), '_favIsHome' => $model['favIsHome']];
        }
        if ($data === []) {
            return;
        }
        $notDraw = fn (array $r): bool => $r['_ht'][0] !== $r['_ht'][1];

        $draws = count($data) - count(array_filter($data, $notDraw));
        $nilNil = count(array_filter($data, fn ($r) => $r['_ht'] === [0, 0]));
        $oneOne = count(array_filter($data, fn ($r) => $r['_ht'] === [1, 1]));
        $this->newLine();
        $this->line(sprintf('━━ 1. LAY EMPATE NO 1º TEMPO ━━ %d jogos · empate HT %.1f%% (0x0 %.1f%%, 1x1 %.1f%%, outros %.1f%%)',
            count($data), $draws / count($data) * 100, $nilNil / count($data) * 100, $oneOne / count($data) * 100,
            ($draws - $nilNil - $oneOne) / count($data) * 100));

        // 1a. Calibração por decil do modelo.
        $this->newLine();
        $this->line('1a. Por decil da chance de empate HT do modelo (acerto = não empatou):');
        $table = [];
        foreach ($this->deciles($data, '_pDraw') as [$label, $slice]) {
            $table[] = $this->layRow($label, $slice, $notDraw, fn ($r) => $r['_pDraw']);
        }
        $this->table(['Faixa modelo', 'Jogos', 'Modelo', 'Empate saiu', 'Acerto dev', 'Acerto val', 'Odd justa lay', 'Entre até'], $table);

        // 1b. Cortes acumulados e filtros simples.
        $filters = [
            'Todos' => fn ($r) => true,
            'Modelo ≤ 30%' => fn ($r) => $r['_pDraw'] <= 0.30,
            'Modelo ≤ 28%' => fn ($r) => $r['_pDraw'] <= 0.28,
            'Modelo ≤ 26%' => fn ($r) => $r['_pDraw'] <= 0.26,
            'Over 0,5 HT < 1,30' => fn ($r) => $r['oddOver05Ht'] < 1.30,
            'Favorito ≥ 70%' => fn ($r) => $r['_fav'] !== null && $r['_fav'] >= 0.70,
            'Fav ≥ 70% e O0,5HT < 1,30' => fn ($r) => $r['_fav'] !== null && $r['_fav'] >= 0.70 && $r['oddOver05Ht'] < 1.30,
            'Fav ≥ 75% e O0,5HT < 1,25' => fn ($r) => $r['_fav'] !== null && $r['_fav'] >= 0.75 && $r['oddOver05Ht'] < 1.25,
        ];
        $this->newLine();
        $this->line('1b. Filtros (acumulados):');
        $table = [];
        foreach ($filters as $label => $filter) {
            $table[] = $this->layRow($label, array_values(array_filter($data, $filter)), $notDraw, fn ($r) => $r['_pDraw']);
        }
        $this->table(['Filtro', 'Jogos', 'Modelo', 'Empate saiu', 'Acerto dev', 'Acerto val', 'Odd justa lay', 'Entre até'], $table);

        // 1c. Variante: BACK favorito vencendo no intervalo.
        $favWinsHt = fn (array $r): bool => $r['_favIsHome'] ? $r['_ht'][0] > $r['_ht'][1] : $r['_ht'][1] > $r['_ht'][0];
        $this->newLine();
        $this->line('1c. Variante BACK favorito vencendo no 1º tempo, por força do favorito (1X2 sem margem):');
        $table = [];
        foreach ([[0.0, 0.5, '< 50%'], [0.5, 0.6, '50–60%'], [0.6, 0.7, '60–70%'], [0.7, 0.8, '70–80%'], [0.8, 1.01, '≥ 80%']] as [$low, $high, $label]) {
            $slice = array_values(array_filter($data, fn ($r) => $r['_fav'] !== null && $r['_fav'] >= $low && $r['_fav'] < $high));
            $s = $this->periods($slice, $favWinsHt);
            $hit = $s['all']['hitRate'];
            $table[] = [$label, $s['all']['entries'],
                $this->pct($slice === [] ? null : array_sum(array_column($slice, '_pFavHt')) / count($slice) * 100),
                HitRateSummary::percent($hit), HitRateSummary::percent($s['dev']['hitRate']), HitRateSummary::percent($s['val']['hitRate']),
                HitRateSummary::backBreakeven($hit),
                $hit === null || $hit <= 0 ? '—' : number_format($this->minBackOdd($hit / 100), 2)];
        }
        $this->table(['Favorito', 'Jogos', 'Modelo', 'Acerto', 'Acerto dev', 'Acerto val', 'Odd justa', 'Entre a partir de'], $table);
        $this->line('   Sem odd de HT no histórico: compare "Entre até"/"Entre a partir de" com a odd da exchange na hora.');
    }

    /** @return array{0: int, 1: int}|null */
    private function htGoals(array $row): ?array
    {
        if (! is_string($row['htScore'] ?? null) || ! preg_match('/^(\d+)-(\d+)$/', $row['htScore'], $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Poisson do 1º tempo: λ total pelo over/under 0,5 HT sem margem, divisão casa/fora pela do
     * jogo inteiro (MarketPoisson::expectedGoals).
     *
     * @return array{draw: float, favourite: float, lambda: float, favIsHome: bool}|null
     */
    private function htModel(array $row): ?array
    {
        $pOver = $this->fairFromPair($row['oddOver05Ht'] ?? null, $row['oddUnder05Ht'] ?? null);
        $goals = MarketPoisson::expectedGoals($row);
        if ($pOver === null || $goals === null || $goals['home'] + $goals['away'] <= 0) {
            return null;
        }
        $lambda = -log(max(1e-6, 1 - min(0.999, $pOver)));
        $share = $goals['home'] / ($goals['home'] + $goals['away']);
        $home = $lambda * $share;
        $away = $lambda * (1 - $share);

        $draw = 0.0;
        $homeWins = 0.0;
        $awayWins = 0.0;
        for ($h = 0; $h <= 8; $h++) {
            for ($a = 0; $a <= 8; $a++) {
                $p = MarketPoisson::poisson($h, $home) * MarketPoisson::poisson($a, $away);
                if ($h === $a) {
                    $draw += $p;
                } elseif ($h > $a) {
                    $homeWins += $p;
                } else {
                    $awayWins += $p;
                }
            }
        }
        $favIsHome = (float) $row['oddHome'] <= (float) $row['oddAway'];

        return ['draw' => $draw, 'favourite' => $favIsHome ? $homeWins : $awayWins, 'lambda' => $lambda, 'favIsHome' => $favIsHome];
    }

    private function layRow(string $label, array $slice, callable $isHit, callable $model): array
    {
        $s = $this->periods($slice, $isHit);
        $hit = $s['all']['hitRate'];
        $fair = $hit === null ? null : LayPricing::fairOdd(100 - $hit);

        return [$label, $s['all']['entries'],
            $this->pct($slice === [] ? null : array_sum(array_map($model, $slice)) / count($slice) * 100),
            $this->pct($hit === null ? null : 100 - $hit),
            HitRateSummary::percent($s['dev']['hitRate']), HitRateSummary::percent($s['val']['hitRate']),
            $fair === null ? '—' : number_format($fair, 2),
            $fair === null ? '—' : number_format(LayPricing::maxEntryOdd($fair), 2)];
    }

    // ---------------------------------------------------------------------------------------
    // 2. Over / under 2,5 com edge de liga
    // ---------------------------------------------------------------------------------------

    private function overUnder25(array $rows): void
    {
        $data = array_values(array_filter($rows, fn ($r) => $this->fairFromPair($r['oddOver25'] ?? null, $r['oddUnder25'] ?? null) !== null));
        if ($data === []) {
            return;
        }
        $isOver = fn (array $r): bool => $r['homeGoals'] + $r['awayGoals'] >= 3;

        $mismatch = count(array_filter($data, fn ($r) => in_array($r['resultOver25'] ?? null, ['green', 'red'], true)
            && ($r['resultOver25'] === 'green') !== $isOver($r)));
        $this->newLine();
        $this->line(sprintf('━━ 2. OVER / UNDER 2,5 COM EDGE DE LIGA ━━ %d jogos · over saiu %.1f%% · divergências com GouR da planilha: %d',
            count($data), count(array_filter($data, $isOver)) / count($data) * 100, $mismatch));

        $sides = [
            'Over 2,5' => [
                'prob' => fn ($r) => $this->fairFromPair($r['oddOver25'], $r['oddUnder25']),
                'odd' => fn ($r) => (float) $r['oddOver25'],
                'hit' => $isOver,
            ],
            'Under 2,5' => [
                'prob' => fn ($r) => 1 - $this->fairFromPair($r['oddOver25'], $r['oddUnder25']),
                'odd' => fn ($r) => (float) $r['oddUnder25'],
                'hit' => fn ($r) => ! $isOver($r),
            ],
        ];
        $this->backMarketReport('2', $data, $sides, [
            'Todos' => [null, null],
            'Liga ≥ +2pp' => [0.02, null],
            'Liga ≥ +4pp' => [0.04, null],
            'Liga ≥ +6pp' => [0.06, null],
            'Liga ≥ +4pp e justa < 1,70' => [0.04, 1.70],
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // 3. Incoerência entre mercados
    // ---------------------------------------------------------------------------------------

    private function crossMarket(array $rows): void
    {
        $data = [];
        foreach ($rows as $row) {
            $p15 = $this->fairFromPair($row['oddOver15'] ?? null, $row['oddUnder15'] ?? null);
            $p25 = $this->fairFromPair($row['oddOver25'] ?? null, $row['oddUnder25'] ?? null);
            if ($p15 === null || $p25 === null || $p15 <= 0.3 || $p15 >= 0.99) {
                continue;
            }
            $lambda = $this->bisect(0.05, 8.0, fn (float $l): float => (1 - exp(-$l) * (1 + $l)) - $p15);
            $implied = 1 - exp(-$lambda) * (1 + $lambda + $lambda * $lambda / 2);
            $data[] = [...$row, '_p25' => $p25, '_gap' => $p25 - $implied];
        }
        if ($data === []) {
            return;
        }
        $isOver = fn (array $r): bool => $r['homeGoals'] + $r['awayGoals'] >= 3;

        $this->newLine();
        $this->line(sprintf('━━ 3. INCOERÊNCIA OVER 1,5 × OVER 2,5 ━━ %d jogos · gap = P(over 2,5) do preço − P implícita no over 1,5 (Poisson)', count($data)));
        $this->line('   Gap negativo: o over 2,5 está barato frente ao over 1,5 → back over. Gap positivo → back under.');
        $this->line('   Poisson tem viés próprio; o que importa é a diferença entre os decis, não o decil isolado.');

        $table = [];
        foreach ($this->deciles($data, '_gap') as [$label, $slice]) {
            $line = [$label, count($slice),
                $this->pct(array_sum(array_column($slice, '_p25')) / count($slice) * 100),
                $this->pct(count(array_filter($slice, $isOver)) / count($slice) * 100)];
            foreach (['over' => [$isOver, 'oddOver25', fn ($r) => $r['_p25']], 'under' => [fn ($r) => ! $isOver($r), 'oddUnder25', fn ($r) => 1 - $r['_p25']]] as [$hit, $oddKey, $prob]) {
                foreach (['dev' => fn ($r) => $r['matchDate'] < $this->cut, 'val' => fn ($r) => $r['matchDate'] >= $this->cut] as $filter) {
                    $period = array_values(array_filter($slice, $filter));
                    $line[] = $this->roi($period, $hit, fn ($r) => 1 / $prob($r));
                }
            }
            $table[] = $line;
        }
        $this->table(['Faixa gap', 'Jogos', 'Preço over', 'Over saiu', 'Over dev', 'Over val', 'Under dev', 'Under val'], $table);
        $this->line('   ROI à odd justa com comissão.');

        // 3b. Detalhe do lado under nas faixas altas de gap — gap absurdo costuma ser odd errada.
        $filters = [
            'Gap +3 a +15pp' => fn ($r) => $r['_gap'] >= 0.03 && $r['_gap'] < 0.15,
            'Gap +4 a +15pp' => fn ($r) => $r['_gap'] >= 0.04 && $r['_gap'] < 0.15,
            'Gap +5 a +15pp' => fn ($r) => $r['_gap'] >= 0.05 && $r['_gap'] < 0.15,
            'Gap +6 a +15pp' => fn ($r) => $r['_gap'] >= 0.06 && $r['_gap'] < 0.15,
            'Gap +8 a +15pp' => fn ($r) => $r['_gap'] >= 0.08 && $r['_gap'] < 0.15,
            'Gap ≥ +15pp (suspeito)' => fn ($r) => $r['_gap'] >= 0.15,
        ];
        $table = [];
        $byYear = [];
        $under = fn ($r) => ! $isOver($r);
        $allYears = array_values(array_unique(array_map(fn ($r) => substr($r['matchDate'], 0, 4), $data)));
        sort($allYears);
        foreach ($filters as $label => $filter) {
            $slice = array_values(array_filter($data, $filter));
            foreach (['Dev' => fn ($r) => $r['matchDate'] < $this->cut, 'Val' => fn ($r) => $r['matchDate'] >= $this->cut] as $period => $inPeriod) {
                $p = array_values(array_filter($slice, $inPeriod));
                $table[] = [$label, $period, count($p),
                    $this->pct($p === [] ? null : count(array_filter($p, $under)) / count($p) * 100),
                    $this->pct($p === [] ? null : array_sum(array_map(fn ($r) => 1 - $r['_p25'], $p)) / count($p) * 100),
                    $this->roi($p, $under, fn ($r) => 1 + ((float) $r['oddUnder25'] - 1) / (1 - self::COMMISSION)),
                    $this->roi($p, $under, fn ($r) => 1 / (1 - $r['_p25'])),
                    $this->roi($p, $under, fn ($r) => 1 / (1 - $r['_p25']) * 0.99),
                    $this->roi($p, $under, fn ($r) => 1 / (1 - $r['_p25']) * 0.98)];
            }
            $line = [$label];
            foreach ($allYears as $year) {
                $y = array_values(array_filter($slice, fn ($r) => str_starts_with($r['matchDate'], $year)));
                $line[] = $y === [] ? '—' : count($y).' · '.$this->roi($y, $under, fn ($r) => 1 / (1 - $r['_p25']));
            }
            $byYear[] = $line;
        }
        $this->newLine();
        $this->line('3b. BACK under 2,5 quando o over 2,5 está caro frente ao over 1,5:');
        $this->table(['Filtro', 'Período', 'Entradas', 'Under saiu', 'Prob. justa', 'ROI casa', 'ROI justa', 'Justa −1%', 'Justa −2%'], $table);
        $this->table(['Filtro', ...$allYears], $byYear);
    }

    // ---------------------------------------------------------------------------------------
    // 4. Match odds
    // ---------------------------------------------------------------------------------------

    private function matchOdds(array $rows): void
    {
        $data = [];
        foreach ($rows as $row) {
            $book = $this->book1x2($row);
            if ($book === null) {
                continue;
            }
            $favIsHome = $row['oddHome'] <= $row['oddAway'];
            $data[] = [...$row,
                '_pFav' => ($favIsHome ? 1 / $row['oddHome'] : 1 / $row['oddAway']) / $book,
                '_pDrawFt' => (1 / $row['oddDraw']) / $book,
                '_oddFav' => $favIsHome ? (float) $row['oddHome'] : (float) $row['oddAway'],
                '_favIsHome' => $favIsHome,
                '_p25' => $this->fairFromPair($row['oddOver25'] ?? null, $row['oddUnder25'] ?? null),
            ];
        }
        if ($data === []) {
            return;
        }
        $isDraw = fn (array $r): bool => $r['homeGoals'] === $r['awayGoals'];
        $favWins = fn (array $r): bool => $r['_favIsHome'] ? $r['homeGoals'] > $r['awayGoals'] : $r['awayGoals'] > $r['homeGoals'];

        $this->newLine();
        $this->line(sprintf('━━ 4. MATCH ODDS ━━ %d jogos · empate FT %.1f%% · favorito venceu %.1f%%',
            count($data), count(array_filter($data, $isDraw)) / count($data) * 100, count(array_filter($data, $favWins)) / count($data) * 100));

        // 4a. LAY empate FT.
        $filters = [
            'Todos (controle)' => fn ($r) => true,
            'Favorito ≥ 60%' => fn ($r) => $r['_pFav'] >= 0.60,
            'Favorito ≥ 70%' => fn ($r) => $r['_pFav'] >= 0.70,
            'Favorito ≥ 75%' => fn ($r) => $r['_pFav'] >= 0.75,
            'Fav ≥ 70% e over 2,5 ≥ 60%' => fn ($r) => $r['_pFav'] >= 0.70 && $r['_p25'] !== null && $r['_p25'] >= 0.60,
            'Fav ≥ 75% e over 2,5 ≥ 65%' => fn ($r) => $r['_pFav'] >= 0.75 && $r['_p25'] !== null && $r['_p25'] >= 0.65,
            'Jogo equilibrado (fav < 45%) e over 2,5 ≥ 60%' => fn ($r) => $r['_pFav'] < 0.45 && $r['_p25'] !== null && $r['_p25'] >= 0.60,
        ];
        $this->newLine();
        $this->line('4a. LAY empate FT — retorno por responsabilidade laydando a odd justa do empate (e 5% acima dela):');
        $table = [];
        foreach ($filters as $label => $filter) {
            $slice = array_values(array_filter($data, $filter));
            $s = $this->periods($slice, fn ($r) => ! $isDraw($r));
            $line = [$label, count($slice),
                $this->pct($slice === [] ? null : array_sum(array_column($slice, '_pDrawFt')) / count($slice) * 100),
                $this->pct($s['all']['hitRate'] === null ? null : 100 - $s['all']['hitRate']),
                HitRateSummary::percent($s['dev']['hitRate']), HitRateSummary::percent($s['val']['hitRate'])];
            foreach (['dev' => fn ($r) => $r['matchDate'] < $this->cut, 'val' => fn ($r) => $r['matchDate'] >= $this->cut] as $inPeriod) {
                foreach ([1.0, 1.05] as $markup) {
                    $period = array_values(array_filter($slice, $inPeriod));
                    $line[] = $period === [] ? '—' : sprintf('%+.2f%%', array_sum(array_map(
                        fn ($r) => LayPricing::realizedReturn(! $isDraw($r), 1 / $r['_pDrawFt'] * $markup), $period)) / count($period) * 100);
                }
            }
            $table[] = $line;
        }
        $this->table(['Filtro', 'Jogos', 'Preço empate', 'Empate saiu', 'Acerto dev', 'Acerto val', 'Dev justa', 'Dev +5%', 'Val justa', 'Val +5%'], $table);

        // 4b. BACK favorito com edge de liga.
        $sides = [
            'Back favorito' => [
                'prob' => fn ($r) => $r['_pFav'],
                'odd' => fn ($r) => $r['_oddFav'],
                'hit' => $favWins,
            ],
        ];
        $this->backMarketReport('4b', $data, $sides, [
            'Todos' => [null, null],
            'Liga ≥ +2pp' => [0.02, null],
            'Liga ≥ +4pp' => [0.04, null],
            'Justa < 1,60 (controle)' => [null, 1.60],
            'Liga ≥ +2pp e justa < 1,60' => [0.02, 1.60],
            'Liga ≥ +4pp e justa < 1,60' => [0.04, 1.60],
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Back com edge de liga — compartilhado por 2 e 4b
    // ---------------------------------------------------------------------------------------

    /**
     * @param array<string, array{prob: callable, odd: callable, hit: callable}> $sides
     * @param array<string, array{0: ?float, 1: ?float}> $profiles label => [edge mínimo, odd justa máxima]
     */
    private function backMarketReport(string $section, array $data, array $sides, array $profiles): void
    {
        $allYears = array_values(array_unique(array_map(fn ($r) => substr($r['matchDate'], 0, 4), $data)));
        sort($allYears);
        foreach ($sides as $side => $fn) {
            $edges = $this->rollingLeagueEdges($data, $fn['prob'], $fn['hit']);
            $table = [];
            $byYear = [];
            foreach ($profiles as $label => [$minEdge, $maxFairOdd]) {
                $slice = [];
                foreach ($data as $index => $row) {
                    if ($minEdge !== null && ($edges[$index] === null || $edges[$index] < $minEdge)) {
                        continue;
                    }
                    if ($maxFairOdd !== null && 1 / $fn['prob']($row) >= $maxFairOdd) {
                        continue;
                    }
                    $slice[] = $row;
                }
                foreach (['Dev' => fn ($r) => $r['matchDate'] < $this->cut, 'Val' => fn ($r) => $r['matchDate'] >= $this->cut] as $period => $inPeriod) {
                    $p = array_values(array_filter($slice, $inPeriod));
                    $table[] = [$label, $period, count($p),
                        $this->pct($p === [] ? null : count(array_filter($p, $fn['hit'])) / count($p) * 100),
                        $this->pct($p === [] ? null : array_sum(array_map($fn['prob'], $p)) / count($p) * 100),
                        $this->roi($p, $fn['hit'], fn ($r) => (1 + ($fn['odd']($r) - 1) / (1 - self::COMMISSION))), // odd da casa, sem comissão
                        $this->roi($p, $fn['hit'], fn ($r) => 1 / $fn['prob']($r)),
                        $this->roi($p, $fn['hit'], fn ($r) => 1 / $fn['prob']($r) * 0.99),
                        $this->roi($p, $fn['hit'], fn ($r) => 1 / $fn['prob']($r) * 0.98)];
                }
                $line = [$label];
                foreach ($allYears as $year) {
                    $y = array_values(array_filter($slice, fn ($r) => str_starts_with($r['matchDate'], $year)));
                    $line[] = $y === [] ? '—' : count($y).' · '.$this->roi($y, $fn['hit'], fn ($r) => 1 / $fn['prob']($r));
                }
                $byYear[] = $line;
            }

            $this->newLine();
            $this->line(sprintf('%s. %s — ROI por unidade (casa = odd com margem, sem comissão; justa = sem margem, com comissão):', $section, $side));
            $this->table(['Perfil', 'Período', 'Entradas', 'Acerto', 'Prob. justa', 'ROI casa', 'ROI justa', 'Justa −1%', 'Justa −2%'], $table);
            $this->line(sprintf('%s. %s — por ano, ROI à odd justa (entradas · ROI):', $section, $side));
            $this->table(['Perfil', ...$allYears], $byYear);
        }
    }

    /**
     * Edge da liga (acerto − prob. justa) usando só os jogos anteriores; null com < 100 jogos.
     *
     * @return list<?float>
     */
    private function rollingLeagueEdges(array $rows, callable $prob, callable $hit): array
    {
        $totals = [];
        $edges = [];
        foreach ($rows as $row) {
            $league = (string) ($row['competition'] ?? '');
            $t = $totals[$league] ?? ['n' => 0, 'greens' => 0, 'expected' => 0.0];
            $edges[] = $t['n'] >= self::MIN_LEAGUE_ENTRIES ? ($t['greens'] - $t['expected']) / $t['n'] : null;
            $t['n']++;
            $t['greens'] += $hit($row) ? 1 : 0;
            $t['expected'] += $prob($row);
            $totals[$league] = $t;
        }

        return $edges;
    }

    // ---------------------------------------------------------------------------------------
    // Utilitários
    // ---------------------------------------------------------------------------------------

    /** ROI de back por unidade, com comissão sobre o lucro, à odd dada por linha. */
    private function roi(array $rows, callable $hit, callable $odd): string
    {
        if ($rows === []) {
            return '—';
        }
        $total = 0.0;
        foreach ($rows as $row) {
            $total += $hit($row) ? ($odd($row) - 1) * (1 - self::COMMISSION) : -1.0;
        }

        return sprintf('%+.2f%%', $total / count($rows) * 100);
    }

    /** @return array{all: array, dev: array, val: array} */
    private function periods(array $rows, callable $isHit): array
    {
        return [
            'all' => HitRateSummary::of($rows, $isHit),
            'dev' => HitRateSummary::of(array_values(array_filter($rows, fn ($r) => $r['matchDate'] < $this->cut)), $isHit),
            'val' => HitRateSummary::of(array_values(array_filter($rows, fn ($r) => $r['matchDate'] >= $this->cut)), $isHit),
        ];
    }

    /** @return list<array{0: string, 1: list<array<string, mixed>>}> */
    private function deciles(array $rows, string $key): array
    {
        usort($rows, fn ($a, $b) => $a[$key] <=> $b[$key]);
        $n = count($rows);
        $out = [];
        for ($d = 0; $d < 10; $d++) {
            $slice = array_slice($rows, (int) floor($n * $d / 10), (int) floor($n * ($d + 1) / 10) - (int) floor($n * $d / 10));
            if ($slice === []) {
                continue;
            }
            $out[] = [sprintf('%+.1f … %+.1f', $slice[0][$key] * 100, $slice[count($slice) - 1][$key] * 100), $slice];
        }

        return $out;
    }

    /** Probabilidade do primeiro lado de um par, margem tirada proporcionalmente. */
    private function fairFromPair(mixed $first, mixed $second): ?float
    {
        if (! is_numeric($first) || ! is_numeric($second) || (float) $first <= 1.0 || (float) $second <= 1.0) {
            return null;
        }

        return (1 / (float) $first) / (1 / (float) $first + 1 / (float) $second);
    }

    private function book1x2(array $row): ?float
    {
        foreach (['oddHome', 'oddDraw', 'oddAway'] as $key) {
            if (! is_numeric($row[$key] ?? null) || (float) $row[$key] <= 1.0) {
                return null;
            }
        }

        return 1 / $row['oddHome'] + 1 / $row['oddDraw'] + 1 / $row['oddAway'];
    }

    /** Menor odd de back com retorno esperado ≥ 0 com comissão. */
    private function minBackOdd(float $p): float
    {
        return 1 + (1 - $p) / ($p * (1 - self::COMMISSION));
    }

    private function bisect(float $low, float $high, callable $f): float
    {
        for ($i = 0; $i < 40; $i++) {
            $mid = ($low + $high) / 2;
            $f($mid) < 0 ? $low = $mid : $high = $mid;
        }

        return ($low + $high) / 2;
    }

    private function pct(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1).'%';
    }
}

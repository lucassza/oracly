<?php

namespace App\Console\Commands;

use App\Oracly\Services\Over15ValueStrategy;
use App\Oracly\Services\PunterMatchPickService;
use Illuminate\Console\Command;

/**
 * Mede o BACK no Over 1,5 FT pagando a odd justa na exchange, com comissão.
 *
 * Diferente dos backtests de LAY de placar, aqui existe preço de mercado: match_history traz
 * odds_ft_over15 e odds_ft_under15 de fechamento. O retorno é reportado em três preços — a
 * odd da casa (com margem), a odd justa (sem margem, proxy da exchange) e a justa 1% e 2%
 * abaixo, porque é aí que a estratégia vive ou morre.
 *
 * O edge da liga de cada jogo é calculado só com os jogos ANTERIORES a ele
 * (Over15ValueStrategy::rollingLeagueEdges). Calcular no histórico inteiro vazaria o resultado
 * do próprio jogo para o filtro e inflaria o retorno.
 */
class BacktestOver15Value extends Command
{
    protected $signature = 'punter:backtest-over15
        {--limit=60000 : Linhas lidas do match_history}
        {--cut=2025-07-01 : Data que separa desenvolvimento (antes) de validação (a partir)}
        {--top=10 : Ligas mostradas no breakdown}';

    protected $description = 'Mede o back no Over 1.5 FT à odd justa da exchange, com edge de liga sem vazamento';

    /** Descontos sobre a odd justa simulados na tabela 3. */
    private const PRICE_DISCOUNTS = [0.0, 0.01, 0.02];

    public function handle(PunterMatchPickService $picks, Over15ValueStrategy $strategy): int
    {
        $rows = array_values(array_filter(
            $picks->history((int) $this->option('limit')),
            fn (array $row): bool => $strategy->result($row) !== null && $strategy->fairProbability($row) !== null,
        ));
        if ($rows === []) {
            $this->warn('Nenhum jogo apurado com odd de over 1,5.');

            return self::SUCCESS;
        }
        usort($rows, fn (array $a, array $b): int => ($a['matchDate'] ?? '') <=> ($b['matchDate'] ?? ''));

        $edges = $strategy->rollingLeagueEdges($rows);
        foreach ($rows as $index => $row) {
            $rows[$index]['_edge'] = $edges[$index];
        }

        $cut = (string) $this->option('cut');
        $this->info(sprintf(
            '%d jogos apurados · %s a %s · validação a partir de %s · comissão %.1f%%.',
            count($rows), $rows[0]['matchDate'], $rows[count($rows) - 1]['matchDate'], $cut, Over15ValueStrategy::COMMISSION * 100,
        ));

        $this->overround($rows);
        $this->byProfile($rows, $strategy, $cut);
        $this->byPrice($rows, $strategy, $cut);
        $this->byYear($rows, $strategy);
        $this->leagues($rows, $strategy);

        $this->newLine();
        $this->line('Preço de fechamento. A lista do dia usa odd de ABERTURA: o que vale é a odd da exchange na hora da entrada.');

        return self::SUCCESS;
    }

    /** Tabela 1 — calibra Over15ValueStrategy::OVERROUND_BY_ODD. */
    private function overround(array $rows): void
    {
        $bands = ['1.00' => [], '1.15' => [], '1.25' => [], '1.35' => [], '1.50' => [], '1.70' => []];
        foreach ($rows as $row) {
            $over = (float) $row['oddOver15'];
            $under = (float) ($row['oddUnder15'] ?? 0);
            if ($under <= 1.0) {
                continue;
            }
            $band = '1.00';
            foreach (array_keys($bands) as $floor) {
                if ($over >= (float) $floor) {
                    $band = $floor;
                }
            }
            $bands[$band][] = 1 / $over + 1 / $under;
        }

        $table = [];
        foreach ($bands as $floor => $values) {
            $table[] = [
                'odd ≥ '.$floor,
                count($values),
                $values === [] ? '—' : number_format(array_sum($values) / count($values), 4),
                number_format(Over15ValueStrategy::overroundFor((float) $floor), 4),
            ];
        }

        $this->newLine();
        $this->line('1. Overround over+under 1,5 por faixa da odd do over (medido × constante da estratégia):');
        $this->table(['Faixa', 'Jogos', 'Medido', 'Constante'], $table);
    }

    /** Tabela 2 — perfis, desenvolvimento × validação, à odd da casa e à justa. */
    private function byProfile(array $rows, Over15ValueStrategy $strategy, string $cut): void
    {
        $table = [];
        foreach (Over15ValueStrategy::PROFILES as $profile => $label) {
            $slice = $this->profileRows($rows, $strategy, $profile);
            foreach (['Desenvolvimento' => fn ($r) => $r['matchDate'] < $cut, 'Validação' => fn ($r) => $r['matchDate'] >= $cut] as $period => $filter) {
                $periodRows = array_values(array_filter($slice, $filter));
                $s = $this->summary($periodRows, $strategy, 0.0);
                $table[] = [$label, $period, $s['entries'], $s['hit'], $s['implied'], $s['roiBook'], $s['roi']];
            }
        }

        $this->newLine();
        $this->line('2. Por perfil (ROI casa = odd com margem; ROI justa = sem margem, com comissão):');
        $this->table(['Perfil', 'Período', 'Entradas', 'Acerto', 'Prob. justa', 'ROI casa', 'ROI justa'], $table);
    }

    /** Tabela 3 — quanto abaixo da justa a estratégia ainda aguenta. */
    private function byPrice(array $rows, Over15ValueStrategy $strategy, string $cut): void
    {
        $table = [];
        foreach (Over15ValueStrategy::PROFILES as $profile => $label) {
            $slice = array_values(array_filter($this->profileRows($rows, $strategy, $profile), fn ($r) => $r['matchDate'] >= $cut));
            $line = [$label, count($slice)];
            foreach (self::PRICE_DISCOUNTS as $discount) {
                $line[] = $this->summary($slice, $strategy, $discount)['roi'];
            }
            $table[] = $line;
        }

        $this->newLine();
        $this->line('3. Validação, ROI por preço de entrada:');
        $this->table(['Perfil', 'Entradas', 'Justa', 'Justa −1%', 'Justa −2%'], $table);
    }

    /** Tabela 4 — estabilidade ano a ano. */
    private function byYear(array $rows, Over15ValueStrategy $strategy): void
    {
        $years = array_unique(array_map(fn (array $row): string => substr($row['matchDate'], 0, 4), $rows));
        sort($years);
        $table = [];
        foreach ($years as $year) {
            $line = [$year];
            foreach (array_keys(Over15ValueStrategy::PROFILES) as $profile) {
                $slice = array_values(array_filter($this->profileRows($rows, $strategy, $profile), fn ($r) => str_starts_with($r['matchDate'], $year)));
                $s = $this->summary($slice, $strategy, 0.0);
                $line[] = $s['entries'].' · '.$s['roi'];
            }
            $table[] = $line;
        }

        $this->newLine();
        $this->line('4. Por ano, ROI à odd justa (entradas · ROI):');
        $this->table(['Ano', ...array_values(Over15ValueStrategy::PROFILES)], $table);
    }

    /** Tabela 5 — concentração: de onde vem o retorno do perfil balanced. */
    private function leagues(array $rows, Over15ValueStrategy $strategy): void
    {
        $byLeague = [];
        foreach ($this->profileRows($rows, $strategy, 'balanced') as $row) {
            $byLeague[$row['competition']][] = $row;
        }
        $table = [];
        foreach ($byLeague as $league => $slice) {
            $s = $this->summary($slice, $strategy, 0.0);
            $table[] = [$league, $s['entries'], $s['hit'], $s['roi']];
        }
        usort($table, fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $table = array_slice($table, 0, (int) $this->option('top'));

        $this->newLine();
        $this->line('5. Ligas com mais entradas no perfil balanced:');
        $this->table(['Liga', 'Entradas', 'Acerto', 'ROI justa'], $table);
    }

    /** @return list<array<string, mixed>> */
    private function profileRows(array $rows, Over15ValueStrategy $strategy, string $profile): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $strategy->matchesProfile($row, $profile, $row['_edge'])));
    }

    /** @return array{entries: int, hit: string, implied: string, roiBook: string, roi: string} */
    private function summary(array $rows, Over15ValueStrategy $strategy, float $discount): array
    {
        $n = count($rows);
        if ($n === 0) {
            return ['entries' => 0, 'hit' => '—', 'implied' => '—', 'roiBook' => '—', 'roi' => '—'];
        }
        $greens = 0;
        $implied = 0.0;
        $book = 0.0;
        $fair = 0.0;
        foreach ($rows as $row) {
            $greens += $strategy->result($row) === 'green' ? 1 : 0;
            $implied += $strategy->fairProbability($row);
            $book += $strategy->result($row) === 'green' ? (float) $row['oddOver15'] - 1 : -1;
            $fair += $strategy->settledReturn($row, $strategy->fairOdd($row) * (1 - $discount));
        }

        return [
            'entries' => $n,
            'hit' => number_format($greens / $n * 100, 1).'%',
            'implied' => number_format($implied / $n * 100, 1).'%',
            'roiBook' => sprintf('%+.2f%%', $book / $n * 100),
            'roi' => sprintf('%+.2f%%', $fair / $n * 100),
        ];
    }
}

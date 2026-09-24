<?php

namespace App\Oracly\Services;

/**
 * BACK no Over 1,5 FT na exchange, só quando a odd oferecida paga acima da justa.
 *
 * O mercado de over 1,5 é eficiente: nos 44 mil jogos do punter.match_history, a odd de
 * fechamento da casa (overround médio 1,069) perde entre −2% e −6% em QUALQUER corte — faixa
 * de odd, média de gols, forma dos times, gap Poisson contra o over 2,5. Nenhum filtro vence a
 * margem. O que sobra é o preço: tirada a margem, a odd justa com 6,5% de comissão fica
 * levemente positiva, e o único corte que soma a isso de forma estável é a LIGA.
 *
 * EDGE DA LIGA: acerto observado menos a probabilidade sem margem, medido só com jogos
 * anteriores. Há ligas em que o mercado subestima o over 1,5 de forma persistente. O corte usa
 * o edge cru (≥ 100 jogos); a probabilidade usa o edge encolhido por n/(n+300), para não
 * prometer numa liga pequena o que só a amostra grande sustenta.
 *
 * VALIDAÇÃO TEMPORAL (punter:backtest-over15, 44.059 jogos 2023-01 a 2026-09, validação a
 * partir de 2025-07-01, edge da liga acumulado — nunca com jogos do futuro). ROI à odd justa,
 * com comissão:
 *
 *   perfil      dev n    dev ROI   val n   val ROI   val −1%   val −2%
 *   all         22.822   +0,37%    13.030  +1,04%    +0,08%    −0,88%
 *   balanced     8.629   +0,56%     4.662  +1,55%    +0,58%    −0,38%
 *   strong       3.789   +0,82%     1.489  +4,11%    +3,12%    +2,13%
 *
 * À odd da casa os três perdem (−1,8% a −4,7%). Por ano o strong fica +0,01 / +1,12 / +2,33 /
 * +4,66% (2023→2026): positivo sempre, mas crescendo — parte do ganho recente pode ser regime.
 * O ganho é FINO: pagar 1% abaixo da justa come ~1pp. Só entra com odd ≥ minEntryOdd().
 *
 * A LISTA DO DIA USA ODD DE ABERTURA, o histórico usa odd de fechamento. A probabilidade da
 * lista é uma estimativa; o que decide a entrada é minEntryOdd() contra a odd que a exchange
 * está pagando na hora.
 */
final class Over15ValueStrategy
{
    /** Comissão da Betfair Brasil sobre o lucro. */
    public const COMMISSION = 0.065;

    /** @var array<string, string> */
    public const PROFILES = [
        'all' => 'Odd justa < 1,50',
        'balanced' => 'Liga +2pp e justa < 1,50',
        'strong' => 'Liga +4pp e justa < 1,50',
    ];

    /** Edge cru mínimo da liga por perfil, em fração (0,02 = 2pp). */
    private const PROFILE_LEAGUE_EDGE = [
        'all' => null,
        'balanced' => 0.02,
        'strong' => 0.04,
    ];

    /** Acima disso o over 1,5 vira aposta de odd longa, onde a margem do mercado pesa mais. */
    public const MAX_FAIR_ODD = 1.50;

    /** Jogos anteriores mínimos para a liga ter edge. */
    public const MIN_LEAGUE_ENTRIES = 100;

    /** Encolhimento do edge na probabilidade: n/(n+K). */
    private const LEAGUE_SHRINK = 300;

    /**
     * Overround over+under 1,5 por faixa da odd do over, medido no match_history (43.874 jogos
     * com os dois lados). Usado quando só o over existe — o caso de panel_fixtures.
     *
     *   odd < 1,15  1,0772   1,15–1,25  1,0689   1,25–1,35  1,0690
     *   1,35–1,50   1,0678   1,50–1,70  1,0683   ≥ 1,70     1,0435
     *
     * @var array<string, float> limite inferior da faixa => overround
     */
    private const OVERROUND_BY_ODD = [
        '1.00' => 1.0772,
        '1.15' => 1.0689,
        '1.25' => 1.0690,
        '1.35' => 1.0678,
        '1.50' => 1.0683,
        '1.70' => 1.0435,
    ];

    /**
     * Probabilidade do over 1,5 sem a margem do mercado, entre 0 e 1.
     *
     * @param array<string, mixed> $row
     */
    public function fairProbability(array $row): ?float
    {
        $over = $this->validOdd($row['oddOver15'] ?? null);
        if ($over === null) {
            return null;
        }
        $under = $this->validOdd($row['oddUnder15'] ?? null);
        if ($under !== null) {
            return (1 / $over) / (1 / $over + 1 / $under);
        }

        return min(0.999, (1 / $over) / self::overroundFor($over));
    }

    public static function overroundFor(float $odd): float
    {
        $overround = self::OVERROUND_BY_ODD['1.00'];
        foreach (self::OVERROUND_BY_ODD as $floor => $value) {
            if ($odd >= (float) $floor) {
                $overround = $value;
            }
        }

        return $overround;
    }

    /** @param array<string, mixed> $row */
    public function fairOdd(array $row): ?float
    {
        $probability = $this->fairProbability($row);

        return $probability === null ? null : 1 / $probability;
    }

    /**
     * Green/red pelo placar final; null se o jogo não foi apurado.
     *
     * @param array<string, mixed> $row
     */
    public function result(array $row): ?string
    {
        $home = $row['homeGoals'] ?? null;
        $away = $row['awayGoals'] ?? null;
        if (! is_numeric($home) || ! is_numeric($away)) {
            return null;
        }

        return (int) $home + (int) $away >= 2 ? 'green' : 'red';
    }

    /**
     * Edge atual de cada liga, sobre o histórico inteiro — para a lista do dia.
     *
     * @param iterable<array<string, mixed>> $history
     * @return array<string, array{entries: int, edge: ?float, shrunkEdge: ?float}>
     */
    public function leagueEdges(iterable $history): array
    {
        $totals = [];
        foreach ($history as $row) {
            $this->accumulate($totals, $row);
        }

        return array_map(fn (array $total): array => $this->edgeOf($total), $totals);
    }

    /**
     * Edge da liga de cada linha usando SÓ os jogos anteriores a ela — para o backtest e para o
     * histórico da tela. Espera $history em ordem cronológica ascendente.
     *
     * @param list<array<string, mixed>> $history
     * @return list<array{entries: int, edge: ?float, shrunkEdge: ?float}>
     */
    public function rollingLeagueEdges(array $history): array
    {
        $totals = [];
        $edges = [];
        foreach ($history as $row) {
            $league = (string) ($row['competition'] ?? '');
            $edges[] = $this->edgeOf($totals[$league] ?? ['entries' => 0, 'greens' => 0, 'expected' => 0.0]);
            $this->accumulate($totals, $row);
        }

        return $edges;
    }

    /**
     * Probabilidade estimada: sem margem mais o edge encolhido da liga.
     *
     * @param array<string, mixed> $row
     * @param array{entries: int, edge: ?float, shrunkEdge: ?float}|null $leagueEdge
     */
    public function probability(array $row, ?array $leagueEdge): ?float
    {
        $fair = $this->fairProbability($row);
        if ($fair === null) {
            return null;
        }

        return max(0.001, min(0.999, $fair + ($leagueEdge['shrunkEdge'] ?? 0.0)));
    }

    /**
     * Menor odd de back com retorno esperado ≥ 0, já descontada a comissão sobre o lucro.
     *
     * p·(odd−1)·(1−c) = 1−p  →  odd = 1 + (1−p) / (p·(1−c))
     */
    public static function minEntryOdd(?float $probability): ?float
    {
        if ($probability === null || $probability <= 0) {
            return null;
        }

        return 1 + (1 - $probability) / ($probability * (1 - self::COMMISSION));
    }

    /** Retorno esperado por unidade apostada, em fração, à odd dada. */
    public static function expectedValue(float $probability, float $odd): float
    {
        return $probability * ($odd - 1) * (1 - self::COMMISSION) - (1 - $probability);
    }

    /**
     * Lucro de 1 unidade à odd dada, com comissão; null se não apurado.
     *
     * @param array<string, mixed> $row
     */
    public function settledReturn(array $row, float $odd): ?float
    {
        return match ($this->result($row)) {
            'green' => ($odd - 1) * (1 - self::COMMISSION),
            'red' => -1.0,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param array{entries: int, edge: ?float, shrunkEdge: ?float}|null $leagueEdge
     */
    public function matchesProfile(array $row, string $profile, ?array $leagueEdge): bool
    {
        if (! array_key_exists($profile, self::PROFILE_LEAGUE_EDGE)) {
            return false;
        }
        $fairOdd = $this->fairOdd($row);
        if ($fairOdd === null || $fairOdd >= self::MAX_FAIR_ODD) {
            return false;
        }
        $cut = self::PROFILE_LEAGUE_EDGE[$profile];
        if ($cut === null) {
            return true;
        }

        return ($leagueEdge['edge'] ?? null) !== null && $leagueEdge['edge'] >= $cut;
    }

    /**
     * @param array<string, array{entries: int, greens: int, expected: float}> $totals
     * @param array<string, mixed> $row
     */
    private function accumulate(array &$totals, array $row): void
    {
        $result = $this->result($row);
        $fair = $this->fairProbability($row);
        if ($result === null || $fair === null) {
            return;
        }
        $league = (string) ($row['competition'] ?? '');
        $totals[$league] ??= ['entries' => 0, 'greens' => 0, 'expected' => 0.0];
        $totals[$league]['entries']++;
        $totals[$league]['greens'] += $result === 'green' ? 1 : 0;
        $totals[$league]['expected'] += $fair;
    }

    /**
     * @param array{entries: int, greens: int, expected: float} $total
     * @return array{entries: int, edge: ?float, shrunkEdge: ?float}
     */
    private function edgeOf(array $total): array
    {
        $n = $total['entries'];
        if ($n < self::MIN_LEAGUE_ENTRIES) {
            return ['entries' => $n, 'edge' => null, 'shrunkEdge' => null];
        }
        $edge = ($total['greens'] - $total['expected']) / $n;

        return ['entries' => $n, 'edge' => $edge, 'shrunkEdge' => $edge * $n / ($n + self::LEAGUE_SHRINK)];
    }

    private function validOdd(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 1.0 ? (float) $value : null;
    }
}

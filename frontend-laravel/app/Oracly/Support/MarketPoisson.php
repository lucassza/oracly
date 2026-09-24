<?php

namespace App\Oracly\Support;

/**
 * Gols esperados de cada lado implícitos nas odds de 1X2 e over 2,5, por Poisson independente.
 *
 * A odd de over 2,5 fixa o total esperado de gols; a diferença entre as probabilidades de vitória
 * da casa e do visitante (1X2 sem margem) fixa como esse total se divide. Compartilhado por
 * AgainstTwoTwoStrategy e AgainstNilNilStrategy, que só diferem no placar que leem da grade.
 *
 * Poisson independente erra de forma sistemática em placares específicos (subestima o 2x2,
 * superestima o 0x0 com favorito forte), por isso cada estratégia calibra a saída contra a
 * frequência observada antes de usá-la como odd justa.
 */
final class MarketPoisson
{
    /**
     * Margem média do mercado over/under 2,5 (1/over + 1/under), medida no match_history.
     *
     * Usada quando só o lado over existe — é o caso de panel_fixtures. Com as duas odds a margem
     * sai da própria linha. A seleção quase não muda entre as duas escalas: medido em
     * punter:backtest-lay-2x2, tabela 4.
     */
    public const OVER25_OVERROUND = 1.0688;

    /** Gols por time considerados na soma. P(> 10 gols de um time) é desprezível. */
    private const MAX_GOALS = 10;

    /** 30 passos de bisseção dão precisão de 1e-8 no intervalo — sobra para odd de duas casas. */
    private const BISECTION_STEPS = 30;

    /**
     * @param array<string, mixed> $row
     * @return array{home: float, away: float}|null
     */
    public static function expectedGoals(array $row): ?array
    {
        $over = self::overProbability($row);
        $home = self::validOdd($row['oddHome'] ?? null);
        $draw = self::validOdd($row['oddDraw'] ?? null);
        $away = self::validOdd($row['oddAway'] ?? null);
        if ($over === null || $home === null || $draw === null || $away === null) {
            return null;
        }

        $book = 1 / $home + 1 / $draw + 1 / $away;
        $edge = (1 / $home - 1 / $away) / $book;

        $total = self::bisect(0.05, 8.0, fn (float $t): float => self::overTwoPointFive($t) - $over);
        $share = self::bisect(0.0, 1.0, fn (float $s): float => self::homeEdge($total * $s, $total * (1 - $s)) - $edge);

        return ['home' => $total * $share, 'away' => $total * (1 - $share)];
    }

    /**
     * Probabilidade de vitória do favorito, 1X2 sem margem, entre 0 e 1.
     *
     * @param array<string, mixed> $row
     */
    public static function favouriteProbability(array $row): ?float
    {
        $home = self::validOdd($row['oddHome'] ?? null);
        $draw = self::validOdd($row['oddDraw'] ?? null);
        $away = self::validOdd($row['oddAway'] ?? null);
        if ($home === null || $draw === null || $away === null) {
            return null;
        }

        return max(1 / $home, 1 / $away) / (1 / $home + 1 / $draw + 1 / $away);
    }

    public static function poisson(int $k, float $lambda): float
    {
        $p = exp(-$lambda);
        for ($i = 1; $i <= $k; $i++) {
            $p *= $lambda / $i;
        }

        return $p;
    }

    /** Recalibração logística: 1 / (1 + e^-(a + b·logit(p) + extra)), com p entre 0 e 1. */
    public static function recalibrate(float $probability, float $intercept, float $slope, float $extra = 0.0): float
    {
        $p = min(max($probability, 1e-6), 1 - 1e-6);

        return 1 / (1 + exp(-($intercept + $slope * log($p / (1 - $p)) + $extra)));
    }

    /** @param array<string, mixed> $row */
    private static function overProbability(array $row): ?float
    {
        $over = self::validOdd($row['oddOver25'] ?? null);
        if ($over === null) {
            return null;
        }
        $under = self::validOdd($row['oddUnder25'] ?? null);
        $book = $under !== null ? 1 / $over + 1 / $under : self::OVER25_OVERROUND;

        // Fora desta faixa não há total de gols que a reproduza dentro de [0,05; 8].
        return min(0.98, max(0.02, (1 / $over) / $book));
    }

    private static function overTwoPointFive(float $lambda): float
    {
        $p0 = exp(-$lambda);

        return 1 - $p0 * (1 + $lambda + $lambda * $lambda / 2);
    }

    /** P(casa vence) − P(visitante vence) para dois Poisson independentes. */
    private static function homeEdge(float $home, float $away): float
    {
        $pHome = exp(-$home);
        $pAway = exp(-$away);
        $homeWins = 0.0;
        $awayWins = 0.0;
        $homeCdf = 0.0;
        $awayCdf = 0.0;
        for ($goals = 0; $goals <= self::MAX_GOALS; $goals++) {
            if ($goals > 0) {
                $pHome *= $home / $goals;
                $pAway *= $away / $goals;
            }
            $homeWins += $pHome * $awayCdf;
            $awayWins += $pAway * $homeCdf;
            $homeCdf += $pHome;
            $awayCdf += $pAway;
        }

        return $homeWins - $awayWins;
    }

    /** Raiz de uma função crescente no intervalo; nos extremos, devolve o extremo. */
    private static function bisect(float $low, float $high, callable $f): float
    {
        for ($step = 0; $step < self::BISECTION_STEPS; $step++) {
            $mid = ($low + $high) / 2;
            if ($f($mid) < 0) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }

    private static function validOdd(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 1.0 ? (float) $value : null;
    }
}

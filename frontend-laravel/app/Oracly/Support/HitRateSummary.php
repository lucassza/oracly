<?php

namespace App\Oracly\Support;

/**
 * Assertividade e odd de breakeven, compartilhado pelos comandos de backtest.
 *
 * As duas fórmulas de breakeven são opostas e é fácil trocá-las: numa aposta a favor
 * (back) o breakeven é 1/p, numa aposta contra (lay) é 1/(1-p). Ficam lado a lado
 * de propósito.
 */
final class HitRateSummary
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $isHit
     * @return array{entries: int, greens: int, reds: int, hitRate: ?float}
     */
    public static function of(array $rows, callable $isHit): array
    {
        $entries = count($rows);
        $greens = count(array_filter($rows, $isHit));

        return [
            'entries' => $entries,
            'greens' => $greens,
            'reds' => $entries - $greens,
            'hitRate' => $entries > 0 ? ($greens / $entries) * 100 : null,
        ];
    }

    /**
     * Split temporal: a fração mais antiga vira desenvolvimento, o resto validação.
     * Espera $rows já ordenado por kickoff ascendente.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $isHit
     * @return array{overall: array{entries: int, greens: int, reds: int, hitRate: ?float}, development: array{entries: int, greens: int, reds: int, hitRate: ?float}, validation: array{entries: int, greens: int, reds: int, hitRate: ?float}}
     */
    public static function temporal(array $rows, callable $isHit, float $split = 0.7): array
    {
        $cut = (int) floor(count($rows) * $split);

        return [
            'overall' => self::of($rows, $isHit),
            'development' => self::of(array_slice($rows, 0, $cut), $isHit),
            'validation' => self::of(array_slice($rows, $cut), $isHit),
        ];
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1).'%';
    }

    /** Aposta a favor: abaixo desta odd a estratégia perde dinheiro mesmo acertando. */
    public static function backBreakeven(?float $hitRate): string
    {
        return $hitRate === null || $hitRate <= 0 ? '—' : number_format(1 / ($hitRate / 100), 2);
    }

    /** Aposta contra: acima desta odd a estratégia perde dinheiro mesmo acertando. */
    public static function layBreakeven(?float $hitRate): string
    {
        return $hitRate === null || $hitRate >= 100 ? '—' : number_format(1 / (1 - $hitRate / 100), 2);
    }
}

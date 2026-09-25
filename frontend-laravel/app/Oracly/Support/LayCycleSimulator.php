<?php

namespace App\Oracly\Support;

/**
 * Juros compostos em ciclos, como o operador faz na exchange.
 *
 * O ciclo começa com a responsabilidade base. Cada green reinveste o lucro na entrada seguinte.
 * O ciclo fecha quando a responsabilidade dobra (ou depois de N greens seguidos, se pedido): o
 * lucro fica guardado e a próxima entrada volta à base. Um red perde a responsabilidade daquele
 * momento (base + lucro acumulado no ciclo) e também volta à base.
 *
 * Não há juros compostos entre ciclos: a base é sempre a mesma, então o resultado sai em
 * unidades da base (+2.000 com base 100 = 20 bases de lucro).
 */
final class LayCycleSimulator
{
    /**
     * @param  list<array{date: string, return: float}>  $entries  Em ordem de data; `return` por unidade de responsabilidade.
     * @param  int|null  $length  Greens seguidos para fechar o ciclo; null = até dobrar.
     * @return array{result: float, maxDrawdown: float, completed: int, broken: int, entries: int, months: array<string, float>}
     */
    public static function run(array $entries, float $base = 100.0, ?int $length = null): array
    {
        $pnl = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;
        $stake = $base;
        $streak = 0;
        $completed = 0;
        $broken = 0;
        $months = [];

        foreach ($entries as $entry) {
            $value = $stake * $entry['return'];
            $pnl += $value;
            $month = substr($entry['date'], 0, 7);
            $months[$month] = ($months[$month] ?? 0.0) + $value;

            if ($entry['return'] > 0) {
                $stake += $value;
                $streak++;
                if ($length === null ? $stake >= 2 * $base : $streak >= $length) {
                    $completed++;
                    $stake = $base;
                    $streak = 0;
                }
            } else {
                $broken++;
                $stake = $base;
                $streak = 0;
            }

            $peak = max($peak, $pnl);
            $maxDrawdown = max($maxDrawdown, $peak - $pnl);
        }

        ksort($months);

        return [
            'result' => $pnl,
            'maxDrawdown' => $maxDrawdown,
            'completed' => $completed,
            'broken' => $broken,
            'entries' => count($entries),
            'months' => $months,
        ];
    }
}

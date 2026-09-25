<?php

namespace App\Oracly\Support;

use Carbon\Carbon;

/**
 * Ranking "melhor da hora" e regras de seleção dos sinais LAY do Punter.
 *
 * Saiu de PunterLayList para ser usado também pela tela LAY 0x0 + 0x1. Cada linha precisa de
 * `kickoffAt` (horário real do jogo); a métrica é "menor = pick mais seguro".
 */
final class HourRank
{
    /**
     * Regras de seleção medidas no backtest do LAY 0x1 (ver PunterLayZeroZeroOne).
     *
     * @var array<string, string>
     */
    public const RULES = [
        'best_of_hour' => 'Melhor da hora',
        'per_kickoff' => '1 por horário',
        'no_overlap' => 'Sem sobreposição',
        'all' => 'Todas',
    ];

    /** Duração de um jogo, para a regra sem sobreposição: a próxima entrada só depois do fim. */
    public const MATCH_MINUTES = 115;

    /**
     * Agrupa por hora Brasília e numera o rank (1, 2, 3...) de cada pick dentro da hora, do mais
     * seguro pro menos seguro. Mantém TODAS as linhas; quem quiser cortar filtra pelo rank.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): float  $metric  Menor valor = pick mais seguro.
     * @return list<array<string, mixed>>
     */
    public static function rank(array $rows, callable $metric): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $bucket = Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
            $groups[$bucket][] = $row;
        }

        $selected = [];
        foreach ($groups as $group) {
            usort($group, fn (array $a, array $b): int => $metric($a) <=> $metric($b));
            foreach ($group as $i => $row) {
                $row['rank'] = $i + 1;
                $selected[] = $row;
            }
        }

        return $selected;
    }

    /** Odd do favorito (menor entre casa e fora): quanto menor, mais seguro o pick. */
    public static function bestOdd(array $row): float
    {
        $odds = array_values(array_filter(
            [$row['oddHome'] ?? null, $row['oddAway'] ?? null],
            fn (?float $v): bool => $v !== null,
        ));

        return $odds === [] ? INF : min($odds);
    }

    /**
     * Aplica uma regra de RULES e devolve as linhas escolhidas em ordem de kickoff.
     *
     * - all: tudo.
     * - per_kickoff: 1 por horário de início exato, o de favorito mais forte.
     * - best_of_hour: 1 por hora Brasília (o rank 1 de rank()).
     * - no_overlap: 1 por horário, e a próxima só quando o jogo anterior já acabou.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function select(array $rows, string $rule): array
    {
        // Horário calculado uma vez por linha: Carbon::parse dentro do usort pesa com milhares de sinais.
        $items = [];
        foreach ($rows as $row) {
            if (empty($row['kickoffAt'])) {
                continue;
            }
            $kickoff = Carbon::parse($row['kickoffAt']);
            $items[] = [
                'row' => $row,
                'ts' => $kickoff->getTimestamp(),
                'hour' => $kickoff->timezone('America/Sao_Paulo')->format('Y-m-d H'),
                'odd' => self::bestOdd($row),
            ];
        }
        usort($items, fn (array $a, array $b): int => $a['ts'] <=> $b['ts'] ?: $a['odd'] <=> $b['odd']);

        if ($rule !== 'all') {
            $best = [];
            foreach ($items as $item) {
                $key = $rule === 'best_of_hour' ? $item['hour'] : (string) $item['ts'];
                if (! isset($best[$key]) || $item['odd'] < $best[$key]['odd']) {
                    $best[$key] = $item;
                }
            }
            $items = array_values($best);
            usort($items, fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        }

        if ($rule === 'no_overlap') {
            $free = [];
            $busyUntil = null;
            foreach ($items as $item) {
                if ($busyUntil !== null && $item['ts'] < $busyUntil) {
                    continue;
                }
                $free[] = $item;
                $busyUntil = $item['ts'] + self::MATCH_MINUTES * 60;
            }
            $items = $free;
        }

        return array_column($items, 'row');
    }
}

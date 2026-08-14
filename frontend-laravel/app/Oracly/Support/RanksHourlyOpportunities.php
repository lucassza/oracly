<?php

namespace App\Oracly\Support;

trait RanksHourlyOpportunities
{
    public int $bestPerHourFilter = 0;

    /** @var array<int, string> */
    public const BEST_PER_HOUR_OPTIONS = [
        0 => 'Todas oportunidades',
        1 => 'Somente 👑 melhor da hora',
        2 => 'Top 2 por hora',
        3 => 'Top 3 por hora',
    ];

    public function setBestPerHourFilter(int $value): void
    {
        if (array_key_exists($value, self::BEST_PER_HOUR_OPTIONS)) {
            $this->bestPerHourFilter = $value;
        }
    }

    /** @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>, array<string, mixed>): int $compare
     * @return list<array<string, mixed>>
     */
    protected function rankUpcomingRowsByHour(array $rows, callable $compare): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            $hour = isset($row['kickoffAt'])
                ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H')
                : 'unknown';
            $groups[$hour][] = $index;
        }
        foreach ($groups as $indexes) {
            usort($indexes, fn (int $left, int $right): int => $compare($rows[$left], $rows[$right]));
            foreach ($indexes as $rank => $index) {
                $rows[$index]['opportunityRank'] = $rank + 1;
            }
        }

        return $this->bestPerHourFilter > 0
            ? array_values(array_filter($rows, fn (array $row): bool => ($row['opportunityRank'] ?? PHP_INT_MAX) <= $this->bestPerHourFilter))
            : $rows;
    }
}

<?php

namespace App\Oracly\Services;

use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\X7;

final class DailyPickService
{
    public function __construct(private readonly MatchSnapshotRepository $snapshots) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forDate(string $dateBrasilia): array
    {
        return OraclyCache::remember(OraclyCache::key("daily:{$dateBrasilia}"), function () use ($dateBrasilia) {
            return $this->buildForDate($dateBrasilia);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildForDate(string $dateBrasilia): array
    {
        $byFixture = $this->groupByFixture($this->snapshots->allForBrasiliaDate($dateBrasilia));
        $picks = [];

        foreach ($byFixture as $providerMatchId => $snapshots) {
            usort($snapshots, fn ($a, $b) => strcmp($a['collectedAt'] ?? '', $b['collectedAt'] ?? ''));
            $latest = $snapshots[array_key_last($snapshots)] ?? null;
            if (! $latest || empty($latest['kickoffAt'])) {
                continue;
            }

            $kickoffAt = $latest['kickoffAt'];
            $isFinished = ($latest['status'] ?? null) === 'finished';
            $preKickoff = array_values(array_filter(
                $snapshots,
                fn ($m) => ($m['collectedAt'] ?? '') < $kickoffAt,
            ));

            $lastPred = function (string $key) use ($preKickoff): ?float {
                $value = null;
                foreach ($preKickoff as $match) {
                    $pred = X7::pred($match, $key);
                    if ($pred !== null) {
                        $value = $pred;
                    }
                }

                return $value;
            };

            $pick = [
                'providerMatchId' => $providerMatchId,
                'kickoffAt' => $kickoffAt,
                'dateBrasilia' => BrasiliaDate::fromKickoff($kickoffAt),
                'country' => $latest['country'] ?? null,
                'competition' => $latest['competition'] ?? null,
                'homeTeam' => data_get($latest, 'homeTeam.name', ''),
                'awayTeam' => data_get($latest, 'awayTeam.name', ''),
                'status' => $latest['status'] ?? null,
                'finalGoals' => $isFinished
                    ? (int) data_get($latest, 'score.home', 0) + (int) data_get($latest, 'score.away', 0)
                    : null,
                'homeScore' => $isFinished ? data_get($latest, 'score.home') : null,
                'awayScore' => $isFinished ? data_get($latest, 'score.away') : null,
                'over05' => $lastPred('over_05_ft_over'),
                'under35' => $lastPred('over_35_ft_under'),
                'over15' => $lastPred('over_15_ft_over'),
                'over25' => $lastPred('over_25_ft_over'),
            ];

            if ($pick['over05'] === null && $pick['over15'] === null && $pick['over25'] === null && $pick['under35'] === null) {
                continue;
            }

            $picks[] = $pick;
        }

        usort($picks, fn ($a, $b) => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

        return $picks;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByFixture(array $matches): array
    {
        $groups = [];
        foreach ($matches as $match) {
            $id = $match['providerMatchId'] ?? null;
            if (! $id) {
                continue;
            }
            $groups[$id][] = $match;
        }

        return $groups;
    }
}

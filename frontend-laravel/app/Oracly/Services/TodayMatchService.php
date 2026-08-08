<?php

namespace App\Oracly\Services;

use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\X7;

final class TodayMatchService
{
    private const GOAL_MARKETS = [
        'gols_1t_05_over',
        'gols_1t_15_over',
        'over_05_ft_over',
        'over_15_ft_over',
        'over_25_ft_over',
        'over_35_ft_under',
        'btts_sim',
    ];

    public function __construct(private readonly MatchSnapshotRepository $snapshots) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forDate(string $dateBrasilia): array
    {
        return OraclyCache::remember(OraclyCache::key("today:{$dateBrasilia}"), function () use ($dateBrasilia) {
            return $this->buildForDate($dateBrasilia);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildForDate(string $dateBrasilia): array
    {
        $byFixture = [];
        foreach ($this->snapshots->allForBrasiliaDate($dateBrasilia) as $match) {
            $id = $match['providerMatchId'] ?? null;
            if (! $id) {
                continue;
            }
            $byFixture[$id][] = $match;
        }

        $rows = [];
        foreach ($byFixture as $snapshots) {
            usort($snapshots, fn ($a, $b) => strcmp($a['collectedAt'] ?? '', $b['collectedAt'] ?? ''));
            $latest = $snapshots[array_key_last($snapshots)];
            $latestWithStats = null;
            foreach (array_reverse($snapshots) as $snap) {
                if (data_get($snap, 'statistics.additional.x7Predictions')) {
                    $latestWithStats = $snap;
                    break;
                }
            }
            if ($latestWithStats && $latestWithStats !== $latest) {
                $latest = [
                    ...$latest,
                    'statistics' => array_replace_recursive(
                        $latestWithStats['statistics'] ?? [],
                        $latest['statistics'] ?? [],
                    ),
                ];
            }

            $rows[] = $this->toTodayMatch($latest);
        }

        usort($rows, fn ($a, $b) => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function toTodayMatch(array $match): array
    {
        $predictions = [];
        foreach (self::GOAL_MARKETS as $key) {
            $predictions[$key] = [
                'probability' => X7::pred($match, $key),
                'modelOdd' => X7::odd($match, $key),
            ];
        }

        $over25 = X7::pred($match, 'over_25_ft_over');
        $btts = X7::pred($match, 'btts_sim');
        $bttsPct = data_get($match, 'statistics.bttsPercentage');
        $avg = data_get($match, 'statistics.combinedGoalsAverage');
        $score = 0;
        if (($over25 ?? 0) >= 55) {
            $score++;
        }
        if (($btts ?? 0) >= 55) {
            $score++;
        }
        if (($bttsPct ?? 0) >= 60) {
            $score++;
        }
        if (($avg ?? 0) >= 2.8) {
            $score++;
        }

        return [
            'providerMatchId' => $match['providerMatchId'] ?? '',
            'kickoffAt' => $match['kickoffAt'] ?? null,
            'country' => $match['country'] ?? null,
            'competition' => $match['competition'] ?? null,
            'homeTeam' => data_get($match, 'homeTeam.name', ''),
            'awayTeam' => data_get($match, 'awayTeam.name', ''),
            'status' => $match['status'] ?? null,
            'liveMinute' => $match['liveMinute'] ?? null,
            'homeScore' => data_get($match, 'score.home'),
            'awayScore' => data_get($match, 'score.away'),
            'combinedGoalsAverage' => is_numeric($avg) ? (float) $avg : null,
            'bttsPercentage' => is_numeric($bttsPct) ? (float) $bttsPct : null,
            'signalScore' => $score,
            'predictions' => $predictions,
        ];
    }
}

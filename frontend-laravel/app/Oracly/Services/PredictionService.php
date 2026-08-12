<?php

namespace App\Oracly\Services;

use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Support\OraclyCache;
use App\Oracly\Support\X7;

final class PredictionService
{
    /** @var array<string, array{pred: string, odd: string, historyHit: callable}> */
    public const MARKETS = [
        'over_05_ht' => ['pred' => 'gols_1t_05_over', 'odd' => 'gols_1t_05_over', 'label' => 'O0.5 HT'],
        'over_15_ht' => ['pred' => 'gols_1t_15_over', 'odd' => 'gols_1t_15_over', 'label' => 'O1.5 HT'],
        'over_05_ft' => ['pred' => 'over_05_ft_over', 'odd' => 'over_05_ft_over', 'label' => 'O0.5 FT'],
        'over_15_ft' => ['pred' => 'over_15_ft_over', 'odd' => 'over_15_ft_over', 'label' => 'O1.5 FT'],
        'under_35_ft' => ['pred' => 'over_35_ft_under', 'odd' => 'over_35_ft_under', 'label' => 'U3.5 FT'],
        'btts' => ['pred' => 'btts_sim', 'odd' => 'btts_sim', 'label' => 'Ambas marcam'],
    ];

    public function __construct(private readonly MatchSnapshotRepository $snapshots) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function upcoming(string $market, string $nowIso, float $minProbability = 0): array
    {
        $cacheKey = OraclyCache::key("pred:up:{$market}:{$minProbability}:".substr($nowIso, 0, 13));

        return OraclyCache::remember($cacheKey, fn () => $this->buildUpcoming($market, $nowIso, $minProbability));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildUpcoming(string $market, string $nowIso, float $minProbability): array
    {
        $keys = self::MARKETS[$market] ?? self::MARKETS['over_05_ht'];
        $rows = [];

        foreach ($this->snapshots->latestUpcoming($nowIso) as $match) {
            $probability = X7::pred($match, $keys['pred']);
            if ($probability === null || $probability < $minProbability) {
                continue;
            }

            $rows[] = [
                'providerMatchId' => $match['providerMatchId'] ?? '',
                'kickoffAt' => $match['kickoffAt'] ?? null,
                'country' => $match['country'] ?? null,
                'competition' => $match['competition'] ?? null,
                'homeTeam' => data_get($match, 'homeTeam.name', ''),
                'awayTeam' => data_get($match, 'awayTeam.name', ''),
                ...$this->oddsFields($match['odds'] ?? null),
                'probability' => $probability,
                'modelOdd' => X7::odd($match, $keys['odd']),
                'homeGoalsAverage' => data_get($match, 'statistics.homeGoalsAverage'),
                'awayGoalsAverage' => data_get($match, 'statistics.awayGoalsAverage'),
                'combinedGoalsAverage' => data_get($match, 'statistics.combinedGoalsAverage'),
                'over05Percentage' => data_get($match, 'statistics.over05Percentage'),
                'bttsPercentage' => data_get($match, 'statistics.bttsPercentage'),
                'over25Probability' => X7::pred($match, 'over_25_ft_over'),
                'bttsProbability' => X7::pred($match, 'btts_sim'),
                'companionProbability' => X7::pred($match, 'over_05_ft_over'),
            ];
        }

        usort($rows, function ($a, $b) {
            $kick = strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? '');

            return $kick !== 0 ? $kick : ($b['probability'] <=> $a['probability']);
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $market, float $minProbability = 0): array
    {
        $cacheKey = OraclyCache::key("pred:hist:{$market}:{$minProbability}");

        return OraclyCache::remember($cacheKey, fn () => $this->buildHistory($market, $minProbability), 300);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHistory(string $market, float $minProbability): array
    {
        $keys = self::MARKETS[$market] ?? self::MARKETS['over_05_ht'];
        $ids = $this->snapshots->finishedProviderIds(1500);
        $byFixture = [];
        foreach ($this->snapshots->allForProviderIds($ids) as $match) {
            $id = $match['providerMatchId'] ?? null;
            if (! $id) {
                continue;
            }
            $byFixture[$id][] = $match;
        }

        $rows = [];
        foreach ($byFixture as $providerMatchId => $snapshots) {
            usort($snapshots, fn ($a, $b) => strcmp($a['collectedAt'] ?? '', $b['collectedAt'] ?? ''));
            $settled = null;
            foreach (array_reverse($snapshots) as $snap) {
                if (($snap['status'] ?? null) === 'finished') {
                    $settled = $snap;
                    break;
                }
            }
            $kickoffAt = $settled['kickoffAt'] ?? null;
            if (! $settled || ! $kickoffAt) {
                continue;
            }

            $predicted = null;
            foreach ($snapshots as $snap) {
                if (($snap['collectedAt'] ?? '') >= $kickoffAt) {
                    continue;
                }
                if (X7::pred($snap, $keys['pred']) !== null) {
                    $predicted = $snap;
                }
            }
            if (! $predicted) {
                continue;
            }

            $probability = X7::pred($predicted, $keys['pred']) ?? 0;
            if ($probability < $minProbability) {
                continue;
            }

            $halftimeHomeScore = data_get($settled, 'score.halftimeHome');
            $halftimeAwayScore = data_get($settled, 'score.halftimeAway');
            foreach (array_reverse($snapshots) as $snapshot) {
                if ($halftimeHomeScore === null) {
                    $halftimeHomeScore = data_get($snapshot, 'score.halftimeHome');
                }
                if ($halftimeAwayScore === null) {
                    $halftimeAwayScore = data_get($snapshot, 'score.halftimeAway');
                }
                if ($halftimeHomeScore !== null && $halftimeAwayScore !== null) {
                    break;
                }
            }

            $htGoals = (int) ($halftimeHomeScore ?? 0) + (int) ($halftimeAwayScore ?? 0);
            $ftGoals = (int) data_get($settled, 'score.home', 0) + (int) data_get($settled, 'score.away', 0);
            $hit = match ($market) {
                'over_05_ht' => $htGoals >= 1,
                'over_15_ht' => $htGoals >= 2,
                'over_05_ft' => $ftGoals >= 1,
                'over_15_ft' => $ftGoals >= 2,
                'under_35_ft' => $ftGoals <= 3,
                'btts' => (int) data_get($settled, 'score.home', 0) > 0
                    && (int) data_get($settled, 'score.away', 0) > 0,
                default => false,
            };

            $rows[] = [
                'providerMatchId' => $providerMatchId,
                'kickoffAt' => $kickoffAt,
                'country' => $settled['country'] ?? null,
                'competition' => $settled['competition'] ?? null,
                'homeTeam' => data_get($settled, 'homeTeam.name', ''),
                'awayTeam' => data_get($settled, 'awayTeam.name', ''),
                ...$this->oddsFields($predicted['odds'] ?? null),
                'probability' => $probability,
                'halftimeGoals' => $htGoals,
                'halftimeHomeScore' => $halftimeHomeScore,
                'halftimeAwayScore' => $halftimeAwayScore,
                'finalGoals' => $ftGoals,
                'homeScore' => data_get($settled, 'score.home'),
                'awayScore' => data_get($settled, 'score.away'),
                'homeGoalsAverage' => data_get($predicted, 'statistics.homeGoalsAverage'),
                'awayGoalsAverage' => data_get($predicted, 'statistics.awayGoalsAverage'),
                'hit' => $hit,
                'companionProbability' => X7::pred($predicted, 'over_05_ft_over'),
                'bttsPercentage' => data_get($predicted, 'statistics.bttsPercentage'),
                'over25Probability' => X7::pred($predicted, 'over_25_ft_over'),
                'bttsProbability' => X7::pred($predicted, 'btts_sim'),
                'combinedGoalsAverage' => data_get($predicted, 'statistics.combinedGoalsAverage'),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($b['kickoffAt'] ?? '', $a['kickoffAt'] ?? ''));

        return $rows;
    }

    /** @return array{homeOdd: ?float, drawOdd: ?float, awayOdd: ?float, oddsBookmaker: ?string} */
    private function oddsFields(mixed $odds): array
    {
        return [
            'homeOdd' => is_array($odds) && is_numeric($odds['home'] ?? null) ? (float) $odds['home'] : null,
            'drawOdd' => is_array($odds) && is_numeric($odds['draw'] ?? null) ? (float) $odds['draw'] : null,
            'awayOdd' => is_array($odds) && is_numeric($odds['away'] ?? null) ? (float) $odds['away'] : null,
            'oddsBookmaker' => is_array($odds) && is_string($odds['bookmaker'] ?? null) ? $odds['bookmaker'] : null,
        ];
    }
}

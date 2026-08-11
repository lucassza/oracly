<?php

namespace App\Oracly\Services;

use App\Oracly\Analysis\FinalScoreExclusion;
use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Support\OraclyCache;

final class FinalScoreExclusionService
{
    public function __construct(private readonly MatchSnapshotRepository $snapshots) {}

    /** @return list<array<string, mixed>> */
    public function upcoming(string $nowIso): array
    {
        return OraclyCache::remember(OraclyCache::key('ft-score:up:'.substr($nowIso, 0, 13)), function () use ($nowIso) {
            $rows = [];
            foreach ($this->snapshots->latestUpcoming($nowIso) as $match) {
                $pick = $this->pick($match);
                if (! $pick) {
                    continue;
                }
                $rows[] = $this->row($match, $pick);
            }
            usort($rows, fn ($a, $b) => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

            return $rows;
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return OraclyCache::remember(OraclyCache::key('ft-score:hist'), function () {
            $byFixture = [];
            foreach ($this->snapshots->allForProviderIds($this->snapshots->finishedProviderIds(1500)) as $match) {
                if (! empty($match['providerMatchId'])) {
                    $byFixture[$match['providerMatchId']][] = $match;
                }
            }

            $rows = [];
            foreach ($byFixture as $snapshots) {
                usort($snapshots, fn ($a, $b) => strcmp($a['collectedAt'] ?? '', $b['collectedAt'] ?? ''));
                $settled = null;
                foreach (array_reverse($snapshots) as $snapshot) {
                    if (($snapshot['status'] ?? null) === 'finished') {
                        $settled = $snapshot;
                        break;
                    }
                }
                $kickoffAt = $settled['kickoffAt'] ?? null;
                if (! $settled || ! $kickoffAt) {
                    continue;
                }

                $pick = null;
                foreach ($snapshots as $snapshot) {
                    if (($snapshot['collectedAt'] ?? '') < $kickoffAt && ($candidate = $this->pick($snapshot))) {
                        $pick = $candidate;
                    }
                }
                if (! $pick) {
                    continue;
                }

                $row = $this->row($settled, $pick);
                $row['homeScore'] = data_get($settled, 'score.home');
                $row['awayScore'] = data_get($settled, 'score.away');
                [$halftimeHomeScore, $halftimeAwayScore] = $this->halftimeScore($settled, $snapshots);
                $row['halftimeHomeScore'] = $halftimeHomeScore;
                $row['halftimeAwayScore'] = $halftimeAwayScore;
                $actual = is_numeric($row['homeScore']) && is_numeric($row['awayScore'])
                    ? sprintf('%dx%d', (int) $row['homeScore'], (int) $row['awayScore'])
                    : null;
                $row['actual'] = $actual;
                $row['hit'] = $actual !== null && ! in_array($actual, $pick['excluded'], true);
                $rows[] = $row;
            }
            usort($rows, fn ($a, $b) => strcmp($b['kickoffAt'] ?? '', $a['kickoffAt'] ?? ''));

            return $rows;
        }, 300);
    }

    /** @param array<string, mixed> $match */
    private function pick(array $match): ?array
    {
        return FinalScoreExclusion::pick(
            $this->number(data_get($match, 'statistics.homeGoalsAverage')),
            $this->number(data_get($match, 'statistics.awayGoalsAverage')),
        );
    }

    /** @param array<string, mixed> $match
     * @param array{excluded: list<string>, probabilities: array<string, float>, combinedProbability: float} $pick
     * @return array<string, mixed>
     */
    private function row(array $match, array $pick): array
    {
        return [
            'providerMatchId' => $match['providerMatchId'] ?? '',
            'kickoffAt' => $match['kickoffAt'] ?? null,
            'country' => $match['country'] ?? null,
            'competition' => $match['competition'] ?? null,
            'homeTeam' => data_get($match, 'homeTeam.name', ''),
            'awayTeam' => data_get($match, 'awayTeam.name', ''),
            'excluded' => $pick['excluded'],
            'combinedProbability' => $pick['combinedProbability'],
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Some final snapshots omit the HT breakdown; retain it from the latest
     * snapshot that contains both values.
     *
     * @param array<string, mixed> $settled
     * @param list<array<string, mixed>> $snapshots
     * @return array{0: mixed, 1: mixed}
     */
    private function halftimeScore(array $settled, array $snapshots): array
    {
        $home = data_get($settled, 'score.halftimeHome');
        $away = data_get($settled, 'score.halftimeAway');
        foreach (array_reverse($snapshots) as $snapshot) {
            if ($home === null) {
                $home = data_get($snapshot, 'score.halftimeHome');
            }
            if ($away === null) {
                $away = data_get($snapshot, 'score.halftimeAway');
            }
            if ($home !== null && $away !== null) {
                break;
            }
        }

        return [$home, $away];
    }
}

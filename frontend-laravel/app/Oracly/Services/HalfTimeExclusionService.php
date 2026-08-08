<?php

namespace App\Oracly\Services;

use App\Oracly\Analysis\HalfTimeExclusion;
use App\Oracly\Repositories\MatchSnapshotRepository;
use App\Oracly\Support\OraclyCache;

final class HalfTimeExclusionService
{
    public function __construct(private readonly MatchSnapshotRepository $snapshots) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function upcoming(string $nowIso, ?string $agreementFilter = null): array
    {
        $cacheKey = OraclyCache::key('ht:up:'.substr($nowIso, 0, 13).':'.($agreementFilter ?? 'all'));

        return OraclyCache::remember($cacheKey, function () use ($nowIso, $agreementFilter) {
            $rows = [];
            foreach ($this->snapshots->latestUpcoming($nowIso) as $match) {
                $pick = HalfTimeExclusion::pick(
                    self::num(data_get($match, 'statistics.firstHalf.homeGoalsAverage')),
                    self::num(data_get($match, 'statistics.firstHalf.awayGoalsAverage')),
                    self::oddsTriplet($match['oddsHalfTime'] ?? null),
                    self::oddsTriplet($match['odds'] ?? null),
                );
                if (! $pick) {
                    continue;
                }

                $key = $pick['agreement'].'/'.$pick['sourcesAvailable'];
                if ($agreementFilter && $key !== $agreementFilter) {
                    continue;
                }

                $rows[] = [
                    'providerMatchId' => $match['providerMatchId'] ?? '',
                    'kickoffAt' => $match['kickoffAt'] ?? null,
                    'country' => $match['country'] ?? null,
                    'competition' => $match['competition'] ?? null,
                    'homeTeam' => data_get($match, 'homeTeam.name', ''),
                    'awayTeam' => data_get($match, 'awayTeam.name', ''),
                    'excluded' => $pick['excluded'],
                    'agreement' => $pick['agreement'],
                    'sourcesAvailable' => $pick['sourcesAvailable'],
                    'agreementKey' => $key,
                    'probExcluded' => $pick['probs'][$pick['excluded']],
                ];
            }

            usort($rows, fn ($a, $b) => strcmp($a['kickoffAt'] ?? '', $b['kickoffAt'] ?? ''));

            return $rows;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(?string $agreementFilter = null): array
    {
        $cacheKey = OraclyCache::key('ht:hist:'.($agreementFilter ?? 'all'));

        return OraclyCache::remember($cacheKey, function () use ($agreementFilter) {
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
                $htHome = data_get($settled, 'score.halftimeHome');
                $htAway = data_get($settled, 'score.halftimeAway');
                if (! $settled || ! $kickoffAt || $htHome === null || $htAway === null) {
                    continue;
                }

                $predicted = null;
                foreach ($snapshots as $snap) {
                    if (($snap['collectedAt'] ?? '') >= $kickoffAt) {
                        continue;
                    }
                    $candidate = HalfTimeExclusion::pick(
                        self::num(data_get($snap, 'statistics.firstHalf.homeGoalsAverage')),
                        self::num(data_get($snap, 'statistics.firstHalf.awayGoalsAverage')),
                        self::oddsTriplet($snap['oddsHalfTime'] ?? null),
                        self::oddsTriplet($snap['odds'] ?? null),
                    );
                    if ($candidate) {
                        $predicted = ['match' => $snap, 'pick' => $candidate];
                    }
                }
                if (! $predicted) {
                    continue;
                }

                $pick = $predicted['pick'];
                $key = $pick['agreement'].'/'.$pick['sourcesAvailable'];
                if ($agreementFilter && $key !== $agreementFilter) {
                    continue;
                }

                $actual = $htHome > $htAway ? 'home' : ($htHome < $htAway ? 'away' : 'draw');
                $rows[] = [
                    'providerMatchId' => $providerMatchId,
                    'kickoffAt' => $kickoffAt,
                    'country' => $settled['country'] ?? null,
                    'competition' => $settled['competition'] ?? null,
                    'homeTeam' => data_get($settled, 'homeTeam.name', ''),
                    'awayTeam' => data_get($settled, 'awayTeam.name', ''),
                    'excluded' => $pick['excluded'],
                    'actual' => $actual,
                    'hit' => $pick['excluded'] !== $actual,
                    'agreement' => $pick['agreement'],
                    'sourcesAvailable' => $pick['sourcesAvailable'],
                    'agreementKey' => $key,
                ];
            }

            usort($rows, fn ($a, $b) => strcmp($b['kickoffAt'] ?? '', $a['kickoffAt'] ?? ''));

            return $rows;
        }, 300);
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array{home?: float, draw?: float, away?: float}|null */
    private static function oddsTriplet(mixed $odds): ?array
    {
        if (! is_array($odds)) {
            return null;
        }

        return [
            'home' => isset($odds['home']) && is_numeric($odds['home']) ? (float) $odds['home'] : null,
            'draw' => isset($odds['draw']) && is_numeric($odds['draw']) ? (float) $odds['draw'] : null,
            'away' => isset($odds['away']) && is_numeric($odds['away']) ? (float) $odds['away'] : null,
        ];
    }
}

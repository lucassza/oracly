<?php

namespace Tests\Unit;

use App\Oracly\Services\FirstHalfGoalsHistoryService;
use PHPUnit\Framework\TestCase;

class FirstHalfGoalsHistoryServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snapshot(string $collectedAt, float $pred, array $overrides = []): array
    {
        return array_replace_recursive([
            'collectedAt' => $collectedAt,
            'kickoffAt' => '2026-08-20T18:00:00.000Z',
            'status' => 'not_started',
            'homeTeam' => ['name' => 'Casa'],
            'awayTeam' => ['name' => 'Fora'],
            'statistics' => [
                'firstHalf' => ['homeGoalsAverage' => 0.8, 'awayGoalsAverage' => 0.7],
                'additional' => ['x7Predictions' => ['gols_1t_05_over' => ['pred' => $pred, 'oj' => 1.3]]],
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function settled(int $htHome = 1, int $htAway = 0): array
    {
        return [
            'collectedAt' => '2026-08-20T20:00:00.000Z',
            'kickoffAt' => '2026-08-20T18:00:00.000Z',
            'status' => 'finished',
            'homeTeam' => ['name' => 'Casa'],
            'awayTeam' => ['name' => 'Fora'],
            'score' => ['home' => 2, 'away' => 1, 'halftimeHome' => $htHome, 'halftimeAway' => $htAway],
        ];
    }

    public function test_it_drops_matches_without_a_per_team_halftime_score(): void
    {
        $settled = $this->settled();
        unset($settled['score']['halftimeHome']);

        $this->assertNull(FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 80),
            $settled,
        ]));
    }

    public function test_it_drops_matches_missing_the_away_halftime_score(): void
    {
        $settled = $this->settled();
        unset($settled['score']['halftimeAway']);

        $this->assertNull(FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 80),
            $settled,
        ]));
    }

    public function test_a_goalless_first_half_is_a_red_not_an_exclusion(): void
    {
        $row = FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 80),
            $this->settled(0, 0),
        ]);

        $this->assertNotNull($row);
        $this->assertFalse($row['hit']);
        $this->assertSame(0, $row['halftimeGoals']);
    }

    public function test_it_never_reads_features_from_a_post_kickoff_snapshot(): void
    {
        // saveLiveUpdates mescla as estatísticas anteriores para a frente, então uma linha
        // pós-kickoff carrega predições pré-kickoff e parece limpa. Este é o teste que
        // protege contra vazamento.
        $row = FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 72),
            $this->snapshot('2026-08-20T19:00:00.000Z', 99, [
                'status' => '1st',
                'statistics' => ['firstHalf' => ['homeGoalsAverage' => 5.0, 'awayGoalsAverage' => 5.0]],
            ]),
            $this->settled(),
        ]);

        $this->assertSame(72.0, $row['probability']);
        $this->assertSame(0.8, $row['firstHalfHomeGoalsAverage']);
    }

    public function test_it_uses_the_last_pre_kickoff_snapshot_not_the_first(): void
    {
        $row = FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T06:00:00.000Z', 71, [
                'statistics' => ['firstHalf' => ['homeGoalsAverage' => 0.2, 'awayGoalsAverage' => 0.2]],
            ]),
            $this->snapshot('2026-08-20T17:00:00.000Z', 83, [
                'statistics' => ['firstHalf' => ['homeGoalsAverage' => 1.1, 'awayGoalsAverage' => 0.9]],
            ]),
            $this->settled(),
        ]);

        $this->assertSame(83.0, $row['probability']);
        $this->assertSame(1.1, $row['firstHalfHomeGoalsAverage']);
    }

    public function test_it_flags_both_backfill_provenances(): void
    {
        $base = [$this->snapshot('2026-08-20T10:00:00.000Z', 80), $this->settled()];

        $clean = FirstHalfGoalsHistoryService::rowFor($base);
        $this->assertFalse($clean['usedBackfilledFeatures']);

        $htBackfill = FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 80, ['backfilledHalfTimeStatsAt' => '2026-08-25T00:00:00.000Z']),
            $this->settled(),
        ]);
        $this->assertTrue($htBackfill['usedBackfilledFeatures']);

        $x7Backfill = FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 80, ['backfilledFromX7At' => '2026-08-25T00:00:00.000Z']),
            $this->settled(),
        ]);
        $this->assertTrue($x7Backfill['usedBackfilledFeatures']);
    }

    public function test_it_drops_a_fixture_without_any_pre_kickoff_prediction(): void
    {
        $this->assertNull(FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T19:00:00.000Z', 80, ['status' => '1st']),
            $this->settled(),
        ]));
    }

    public function test_it_keeps_a_row_whose_first_half_averages_are_missing(): void
    {
        $snapshot = $this->snapshot('2026-08-20T10:00:00.000Z', 80);
        unset($snapshot['statistics']['firstHalf']);

        $row = FirstHalfGoalsHistoryService::rowFor([$snapshot, $this->settled()]);

        $this->assertNotNull($row);
        $this->assertNull($row['firstHalfHomeGoalsAverage']);
        $this->assertSame(80.0, $row['probability']);
    }

    public function test_the_fair_odd_comes_from_the_model_not_the_market(): void
    {
        $row = FirstHalfGoalsHistoryService::rowFor([
            $this->snapshot('2026-08-20T10:00:00.000Z', 80, [
                'statistics' => ['additional' => ['x7Predictions' => ['gols_1t_05_over' => ['odd' => 1.22]]]],
            ]),
            $this->settled(),
        ]);

        // `oj`, não `odd` — X7::odd lê a odd justa do modelo.
        $this->assertSame(1.3, $row['fairOdd']);
    }
}

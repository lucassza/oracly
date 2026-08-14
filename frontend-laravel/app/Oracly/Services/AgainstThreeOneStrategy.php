<?php

namespace App\Oracly\Services;

/** Chooses the lower-probability exact score between 3-1 and 1-3. */
final class AgainstThreeOneStrategy extends AgainstOneGoalStrategy
{
    /** @param array<string, mixed> $row
     * @return array{score: string, probability: float, counterAttackAverage: float, goalAverageGap: float}|null
     */
    public function choice(array $row): ?array
    {
        $home = $this->number($row['homeGoalsAverage'] ?? null);
        $away = $this->number($row['awayGoalsAverage'] ?? null);
        if ($home === null || $away === null) {
            return null;
        }
        $home = max(0.08, $home);
        $away = max(0.08, $away);
        $threeOne = $this->poisson($home, 3) * $this->poisson($away, 1);
        $oneThree = $this->poisson($home, 1) * $this->poisson($away, 3);
        $againstThreeOne = $threeOne <= $oneThree;

        return [
            'score' => $againstThreeOne ? '3-1' : '1-3',
            'probability' => $againstThreeOne ? $threeOne : $oneThree,
            // The team projected to score three is the weaker attacking side.
            'counterAttackAverage' => $againstThreeOne ? $home : $away,
            'goalAverageGap' => abs($home - $away),
        ];
    }
}

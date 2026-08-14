<?php

namespace App\Oracly\Services;

/** Deterministic pre-kickoff filters for the 0-1 / 1-0 lay strategy. */
class AgainstOneGoalStrategy
{
    protected const TARGET_GOALS = 1;
    /** @var array<string, string> */
    public const PROFILES = [
        'baseline' => 'Base atual',
        'balanced' => 'Ataque dos dois lados',
        'strong' => 'Sinal forte',
    ];

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
        $zeroAwayGoals = $this->poisson($home, 0) * $this->poisson($away, static::TARGET_GOALS);
        $homeGoalsZero = $this->poisson($home, static::TARGET_GOALS) * $this->poisson($away, 0);
        $againstZeroAwayGoals = $zeroAwayGoals <= $homeGoalsZero;

        return [
            'score' => $againstZeroAwayGoals ? '0-'.static::TARGET_GOALS : static::TARGET_GOALS.'-0',
            'probability' => $againstZeroAwayGoals ? $zeroAwayGoals : $homeGoalsZero,
            // Team which must score to invalidate the selected exact score.
            'counterAttackAverage' => $againstZeroAwayGoals ? $home : $away,
            'goalAverageGap' => abs($home - $away),
        ];
    }

    /** @param array<string, mixed> $row */
    public function matchesProfile(array $row, string $profile): bool
    {
        if ($profile === 'baseline') {
            return true;
        }
        $choice = $this->choice($row);
        $over25 = $this->number($row['over25Probability'] ?? null);
        $btts = $this->number($row['bttsProbability'] ?? null);
        $combined = $this->number($row['combinedGoalsAverage'] ?? null);
        if ($choice === null || $over25 === null || $btts === null || $combined === null) {
            return false;
        }

        return match ($profile) {
            'balanced' => $over25 >= 60 && $btts >= 60 && $combined >= 2.8
                && $choice['counterAttackAverage'] >= 1.0,
            'strong' => $over25 >= 70 && $btts >= 65 && $combined >= 3.2
                && $choice['counterAttackAverage'] >= 1.15 && $choice['goalAverageGap'] >= 0.20,
            default => false,
        };
    }

    protected function poisson(float $average, int $goals): float
    {
        return exp(-$average) * ($average ** $goals);
    }

    protected function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}

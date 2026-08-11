<?php

namespace App\Oracly\Analysis;

final class FinalScoreExclusion
{
    /** @var list<array{home: int, away: int, label: string}> */
    public const CANDIDATES = [
        ['home' => 0, 'away' => 1, 'label' => '0x1'],
        ['home' => 0, 'away' => 2, 'label' => '0x2'],
        ['home' => 1, 'away' => 0, 'label' => '1x0'],
        ['home' => 2, 'away' => 0, 'label' => '2x0'],
        ['home' => 2, 'away' => 1, 'label' => '2x1'],
        ['home' => 1, 'away' => 2, 'label' => '1x2'],
    ];

    /**
     * Selects the two least likely FT scores in the defined candidate set,
     * using the pre-match home/away goal averages as independent Poisson means.
     *
     * @return array{excluded: list<string>, probabilities: array<string, float>, combinedProbability: float}|null
     */
    public static function pick(?float $homeGoalsAverage, ?float $awayGoalsAverage): ?array
    {
        if ($homeGoalsAverage === null || $awayGoalsAverage === null) {
            return null;
        }

        $homeLambda = max(0.08, $homeGoalsAverage);
        $awayLambda = max(0.08, $awayGoalsAverage);
        $probabilities = [];

        foreach (self::CANDIDATES as $candidate) {
            $probabilities[$candidate['label']] = self::poisson($homeLambda, $candidate['home'])
                * self::poisson($awayLambda, $candidate['away']);
        }

        asort($probabilities, SORT_NUMERIC);
        $excluded = array_slice(array_keys($probabilities), 0, 2);

        return [
            'excluded' => $excluded,
            'probabilities' => $probabilities,
            'combinedProbability' => array_sum(array_intersect_key($probabilities, array_flip($excluded))),
        ];
    }

    private static function poisson(float $lambda, int $goals): float
    {
        $factorial = match ($goals) {
            0, 1 => 1,
            2 => 2,
            default => 1,
        };

        return exp(-$lambda) * ($lambda ** $goals) / $factorial;
    }
}

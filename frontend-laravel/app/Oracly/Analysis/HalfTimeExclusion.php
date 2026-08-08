<?php

namespace App\Oracly\Analysis;

final class HalfTimeExclusion
{
    /** @return array{home: float, draw: float, away: float}|null */
    public static function pick(
        ?float $homeAvg,
        ?float $awayAvg,
        ?array $oddsHt = null,
        ?array $oddsFt = null,
    ): ?array {
        if ($homeAvg === null || $awayAvg === null) {
            return null;
        }

        $probs = self::poisson(max(0.08, $homeAvg), max(0.08, $awayAvg));
        $poisson = self::argmin($probs);
        $sources = ['poisson' => $poisson];
        $agreement = 1;
        $available = 1;

        if (isset($oddsHt['home'], $oddsHt['draw'], $oddsHt['away'])) {
            $pick = self::argmin(self::implied((float) $oddsHt['home'], (float) $oddsHt['draw'], (float) $oddsHt['away']));
            $sources['oddsHt'] = $pick;
            $available++;
            if ($pick === $poisson) {
                $agreement++;
            }
        }

        if (isset($oddsFt['home'], $oddsFt['draw'], $oddsFt['away'])) {
            $pick = self::argmin(self::implied((float) $oddsFt['home'], (float) $oddsFt['draw'], (float) $oddsFt['away']));
            $sources['oddsFt'] = $pick;
            $available++;
            if ($pick === $poisson) {
                $agreement++;
            }
        }

        return [
            'excluded' => $poisson,
            'probs' => $probs,
            'sources' => $sources,
            'agreement' => $agreement,
            'sourcesAvailable' => $available,
        ];
    }

    /** @return array{home: float, draw: float, away: float} */
    private static function poisson(float $lambdaHome, float $lambdaAway): array
    {
        $factorials = [1, 1, 2, 6, 24, 120];
        $home = $draw = $away = 0.0;
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 6; $j++) {
                $p = (exp(-$lambdaHome) * ($lambdaHome ** $i) / $factorials[$i])
                    * (exp(-$lambdaAway) * ($lambdaAway ** $j) / $factorials[$j]);
                if ($i > $j) {
                    $home += $p;
                } elseif ($j > $i) {
                    $away += $p;
                } else {
                    $draw += $p;
                }
            }
        }

        return self::normalize($home, $draw, $away);
    }

    /** @return array{home: float, draw: float, away: float} */
    private static function implied(float $h, float $d, float $a): array
    {
        return self::normalize(1 / $h, 1 / $d, 1 / $a);
    }

    /** @return array{home: float, draw: float, away: float} */
    private static function normalize(float $h, float $d, float $a): array
    {
        $t = $h + $d + $a;

        return ['home' => $h / $t, 'draw' => $d / $t, 'away' => $a / $t];
    }

    /** @param  array{home: float, draw: float, away: float}  $probs */
    private static function argmin(array $probs): string
    {
        $best = 'home';
        $value = $probs['home'];
        foreach (['draw', 'away'] as $key) {
            if ($probs[$key] < $value) {
                $best = $key;
                $value = $probs[$key];
            }
        }

        return $best;
    }
}

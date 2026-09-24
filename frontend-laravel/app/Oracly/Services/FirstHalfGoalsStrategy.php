<?php

namespace App\Oracly\Services;

/**
 * Filtros determinísticos pré-jogo para o mercado Over 0.5 HT (1+ gol no 1º tempo).
 *
 * Ao contrário das estratégias AgainstNGoals, esta é uma aposta a favor: não há placar
 * a escolher, a seleção é fixa. Por isso não herda de AgainstOneGoalStrategy.
 *
 * Cortes medidos sobre partidas encerradas com placar de HT real por time, features do
 * último snapshot com collectedAt < kickoffAt, e linhas de backfill excluídas (elas
 * inflam o resultado — ver o aviso em src/types/schemas.ts:127-133):
 *
 *   baseline   pred >= 70                                    n=6118  75,8%  (treino 75,7 / val 76,0)
 *   balanced   pred >= 70 + média 1T >= 1,4 + O1.5HT >= 45   n=1040  80,4%  (treino 79,1 / val 83,0)
 *   strong     pred >= 75 + média 1T >= 1,6 + O1.5HT >= 50   n= 357  85,2%  (treino 84,0 / val 87,6)
 *
 * A superfície de corte é um platô (18 combinações entre 83% e 85,5%, todas estáveis no
 * out-of-sample), então os cortes são números redondos de propósito. Afinar mais é ruído.
 *
 * Medidos e DELIBERADAMENTE fora: firstHalf.*ShotsOnTargetAverage (lift 0,0pp) e
 * *DangerousAttacksAverage (lift -1,2pp). Não readicionar sem novo backtest.
 */
class FirstHalfGoalsStrategy
{
    public const PRED_KEY = 'gols_1t_05_over';

    /** @var array<string, string> */
    public const PROFILES = [
        'baseline' => 'Base X7 ≥ 70%',
        'balanced' => 'Ataque confirmado',
        'strong' => 'Sinal forte',
        'legacy80' => 'Legado: X7 ≥ 80%',
    ];

    private const PRED_BASELINE = 70.0;
    private const PRED_STRONG = 75.0;
    private const PRED_LEGACY = 80.0;
    private const FIRST_HALF_BALANCED = 1.4;
    private const FIRST_HALF_STRONG = 1.6;
    private const OVER_15_HT_BALANCED = 45.0;
    private const OVER_15_HT_STRONG = 50.0;

    /** Piso de lambda por time, espelhando MIN_LAMBDA em src/analysis/half-time-exclusion.ts:29. */
    private const MIN_LAMBDA = 0.08;

    /**
     * Vetor de features canônico, ou null quando falta a probabilidade base do X7.
     *
     * @param  array<string, mixed>  $row
     * @return array{probability: float, firstHalfGoalsAverage: ?float, over15HtProbability: ?float, over25Probability: ?float, ownProbability: ?float}|null
     */
    public function features(array $row): ?array
    {
        $probability = $this->number($row['probability'] ?? $row['over05Ht'] ?? null);
        if ($probability === null) {
            return null;
        }

        return [
            'probability' => $probability,
            'firstHalfGoalsAverage' => $this->firstHalfGoalsAverage($row),
            'over15HtProbability' => $this->number($row['over15HtProbability'] ?? null),
            'over25Probability' => $this->number($row['over25Probability'] ?? null),
            'ownProbability' => $this->ownProbability($row),
        ];
    }

    /** @param array<string, mixed> $row */
    public function matchesProfile(array $row, string $profile): bool
    {
        $features = $this->features($row);
        if ($features === null) {
            return false;
        }

        $pred = $features['probability'];
        $firstHalf = $features['firstHalfGoalsAverage'];
        $over15Ht = $features['over15HtProbability'];
        $over25 = $features['over25Probability'];

        return match ($profile) {
            'baseline' => $pred >= self::PRED_BASELINE,
            'legacy80' => $pred >= self::PRED_LEGACY,
            'balanced' => $pred >= self::PRED_BASELINE
                && $firstHalf !== null && $firstHalf >= self::FIRST_HALF_BALANCED
                && $over15Ht !== null && $over15Ht >= self::OVER_15_HT_BALANCED,
            'strong' => $pred >= self::PRED_STRONG
                && $firstHalf !== null && $firstHalf >= self::FIRST_HALF_STRONG
                && $over15Ht !== null && $over15Ht >= self::OVER_15_HT_STRONG,
            default => false,
        };
    }

    /**
     * Perfil mais restrito que a linha satisfaz, para badge e ordenação.
     *
     * @param  array<string, mixed>  $row
     */
    public function bestProfile(array $row): ?string
    {
        foreach (['strong', 'balanced', 'legacy80', 'baseline'] as $profile) {
            if ($this->matchesProfile($row, $profile)) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Contagem de confirmações e quantas puderam ser avaliadas.
     *
     * Devolve o par score/available em vez de só o score porque nem toda feature está
     * presente em todo snapshot — over15SignalScore (PredictionService.php:211) trata
     * ausente como zero, o que faz uma linha incompleta parecer uma linha ruim.
     *
     * @param  array<string, mixed>  $row
     * @return array{score: int, available: int}
     */
    public function signalScore(array $row): array
    {
        $checks = [
            [$this->firstHalfGoalsAverage($row), self::FIRST_HALF_BALANCED],
            [$this->number($row['over15HtProbability'] ?? null), 40.0],
            [$this->number($row['cornersProbability'] ?? null), 55.0],
            [$this->number($row['over25Probability'] ?? null), 60.0],
            [$this->number($row['combinedGoalsAverage'] ?? null), 3.0],
            [$this->number($row['bttsProbability'] ?? null), 60.0],
        ];

        $score = 0;
        $available = 0;
        foreach ($checks as [$value, $cutoff]) {
            if ($value === null) {
                continue;
            }
            $available++;
            if ($value >= $cutoff) {
                $score++;
            }
        }

        return ['score' => $score, 'available' => $available];
    }

    /**
     * Selo do próprio X7. Exposto para badge, não entra em perfil: n=123 para a
     * combinação Recomendado+EV+ é fino demais e a semântica das tags pode mudar
     * quando o X7 recalibra.
     *
     * @param  array<string, mixed>  $row
     */
    public function hasSeal(array $row, string $seal = 'Recomendado'): bool
    {
        $seals = $row['over05HtSeals'] ?? null;

        return is_array($seals) && in_array($seal, $seals, true);
    }

    /**
     * Estimativa própria a partir das médias de gols do 1º tempo: 1 - exp(-(λh + λa)).
     *
     * Para "1 ou mais gols" essa é a fórmula fechada inteira — não precisa da PMF com
     * fatorial de AgainstOneGoalStrategy::poisson().
     *
     * Atenção: é monótona em λh + λa, então o corte de perfil `média 1T >= 1,4` já É
     * este corte (>= 75,3%). Serve para exibição e comparação lado a lado com o X7,
     * não como segundo critério empilhado.
     *
     * @param  array<string, mixed>  $row
     */
    public function ownProbability(array $row): ?float
    {
        $home = $this->number($row['firstHalfHomeGoalsAverage'] ?? null);
        $away = $this->number($row['firstHalfAwayGoalsAverage'] ?? null);
        if ($home === null || $away === null) {
            return null;
        }

        return (1 - exp(-(max(self::MIN_LAMBDA, $home) + max(self::MIN_LAMBDA, $away)))) * 100;
    }

    /** @param array<string, mixed> $row */
    private function firstHalfGoalsAverage(array $row): ?float
    {
        $home = $this->number($row['firstHalfHomeGoalsAverage'] ?? null);
        $away = $this->number($row['firstHalfAwayGoalsAverage'] ?? null);

        return $home === null || $away === null ? null : $home + $away;
    }

    protected function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}

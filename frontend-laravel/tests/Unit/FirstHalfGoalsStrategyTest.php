<?php

namespace Tests\Unit;

use App\Oracly\Services\FirstHalfGoalsStrategy;
use PHPUnit\Framework\TestCase;

class FirstHalfGoalsStrategyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function strongRow(): array
    {
        return [
            'probability' => 78,
            'firstHalfHomeGoalsAverage' => 0.9,
            'firstHalfAwayGoalsAverage' => 0.8,
            'over15HtProbability' => 55,
            'over25Probability' => 70,
        ];
    }

    public function test_it_returns_no_features_without_the_base_prediction(): void
    {
        $this->assertNull((new FirstHalfGoalsStrategy())->features([
            'firstHalfHomeGoalsAverage' => 0.9,
            'firstHalfAwayGoalsAverage' => 0.8,
        ]));
    }

    public function test_it_reads_the_probability_from_either_row_shape(): void
    {
        $strategy = new FirstHalfGoalsStrategy();

        $this->assertSame(72.0, $strategy->features(['probability' => 72])['probability']);
        $this->assertSame(72.0, $strategy->features(['over05Ht' => 72])['probability']);
    }

    public function test_a_snapshot_without_first_half_averages_fails_the_filtered_profiles(): void
    {
        $strategy = new FirstHalfGoalsStrategy();
        $row = ['probability' => 82, 'over15HtProbability' => 55];

        $this->assertFalse($strategy->matchesProfile($row, 'balanced'));
        $this->assertFalse($strategy->matchesProfile($row, 'strong'));
        // Ausência de média de 1º tempo mede 71,2% de acerto — é sinal negativo, não neutro.
        $this->assertTrue($strategy->matchesProfile($row, 'baseline'));
        $this->assertTrue($strategy->matchesProfile($row, 'legacy80'));
    }

    public function test_the_balanced_profile_requires_every_cutoff(): void
    {
        $strategy = new FirstHalfGoalsStrategy();
        $row = [
            'probability' => 71,
            'firstHalfHomeGoalsAverage' => 0.7,
            'firstHalfAwayGoalsAverage' => 0.7,
            'over15HtProbability' => 45,
        ];

        $this->assertTrue($strategy->matchesProfile($row, 'balanced'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'probability' => 69], 'balanced'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'over15HtProbability' => 44], 'balanced'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'firstHalfAwayGoalsAverage' => 0.69], 'balanced'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'firstHalfAwayGoalsAverage' => null], 'balanced'));
    }

    public function test_the_strong_profile_is_stricter_than_balanced_on_every_axis(): void
    {
        $strategy = new FirstHalfGoalsStrategy();
        $row = $this->strongRow();

        $this->assertTrue($strategy->matchesProfile($row, 'strong'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'probability' => 74], 'strong'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'over15HtProbability' => 49], 'strong'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'firstHalfHomeGoalsAverage' => 0.79], 'strong'));
    }

    public function test_a_strong_row_also_satisfies_balanced(): void
    {
        $strategy = new FirstHalfGoalsStrategy();
        $row = $this->strongRow();

        $this->assertTrue($strategy->matchesProfile($row, 'strong'));
        $this->assertTrue($strategy->matchesProfile($row, 'balanced'));
        $this->assertSame('strong', $strategy->bestProfile($row));
    }

    public function test_best_profile_is_null_when_nothing_matches(): void
    {
        $this->assertNull((new FirstHalfGoalsStrategy())->bestProfile(['probability' => 61]));
    }

    public function test_an_unknown_profile_never_matches(): void
    {
        $this->assertFalse((new FirstHalfGoalsStrategy())->matchesProfile($this->strongRow(), 'inexistente'));
    }

    public function test_every_declared_profile_is_handled(): void
    {
        $strategy = new FirstHalfGoalsStrategy();
        foreach (array_keys(FirstHalfGoalsStrategy::PROFILES) as $profile) {
            // Uma linha que satisfaz tudo deve passar em todo perfil declarado; se um
            // perfil caísse no `default` do match, este assert falharia.
            $this->assertTrue(
                $strategy->matchesProfile([...$this->strongRow(), 'probability' => 88], $profile),
                "perfil {$profile} caiu no default",
            );
        }
    }

    public function test_own_probability_is_the_closed_form_poisson(): void
    {
        $strategy = new FirstHalfGoalsStrategy();

        $this->assertEqualsWithDelta(
            (1 - exp(-1.7)) * 100,
            $strategy->ownProbability(['firstHalfHomeGoalsAverage' => 0.9, 'firstHalfAwayGoalsAverage' => 0.8]),
            1e-12,
        );
        $this->assertNull($strategy->ownProbability(['firstHalfHomeGoalsAverage' => 0.9]));
    }

    public function test_own_probability_applies_the_lambda_floor(): void
    {
        $this->assertEqualsWithDelta(
            (1 - exp(-0.16)) * 100,
            (new FirstHalfGoalsStrategy())->ownProbability([
                'firstHalfHomeGoalsAverage' => 0,
                'firstHalfAwayGoalsAverage' => 0,
            ]),
            1e-12,
        );
    }

    public function test_the_balanced_first_half_cutoff_is_the_poisson_cutoff(): void
    {
        // Os dois não são critérios independentes: 1 - exp(-1.4) = 75,34%.
        $this->assertEqualsWithDelta(
            75.34,
            (new FirstHalfGoalsStrategy())->ownProbability([
                'firstHalfHomeGoalsAverage' => 0.7,
                'firstHalfAwayGoalsAverage' => 0.7,
            ]),
            0.01,
        );
    }

    public function test_signal_score_reports_availability_instead_of_counting_missing_as_zero(): void
    {
        $strategy = new FirstHalfGoalsStrategy();

        $this->assertSame(
            ['score' => 6, 'available' => 6],
            $strategy->signalScore([
                'firstHalfHomeGoalsAverage' => 0.8, 'firstHalfAwayGoalsAverage' => 0.8,
                'over15HtProbability' => 40, 'cornersProbability' => 55,
                'over25Probability' => 60, 'combinedGoalsAverage' => 3.0, 'bttsProbability' => 60,
            ]),
        );

        // Escanteios ausentes reduzem `available`, não pontuam zero.
        $this->assertSame(
            ['score' => 5, 'available' => 5],
            $strategy->signalScore([
                'firstHalfHomeGoalsAverage' => 0.8, 'firstHalfAwayGoalsAverage' => 0.8,
                'over15HtProbability' => 40,
                'over25Probability' => 60, 'combinedGoalsAverage' => 3.0, 'bttsProbability' => 60,
            ]),
        );
    }

    public function test_shots_on_target_and_dangerous_attacks_are_not_scored(): void
    {
        // Medidos e reprovados: lift 0,0pp e -1,2pp. Regressão contra alguém "melhorar"
        // o score readicionando essas features.
        $this->assertSame(
            ['score' => 0, 'available' => 6],
            (new FirstHalfGoalsStrategy())->signalScore([
                'firstHalfHomeGoalsAverage' => 0.1, 'firstHalfAwayGoalsAverage' => 0.1,
                'over15HtProbability' => 10, 'cornersProbability' => 10,
                'over25Probability' => 10, 'combinedGoalsAverage' => 1.0, 'bttsProbability' => 10,
                'firstHalfHomeShotsOnTargetAverage' => 99, 'firstHalfAwayShotsOnTargetAverage' => 99,
                'firstHalfHomeDangerousAttacksAverage' => 99, 'firstHalfAwayDangerousAttacksAverage' => 99,
            ]),
        );
    }

    public function test_it_reads_the_x7_seal(): void
    {
        $strategy = new FirstHalfGoalsStrategy();

        $this->assertTrue($strategy->hasSeal(['over05HtSeals' => ['Recomendado', 'EV+']]));
        $this->assertFalse($strategy->hasSeal(['over05HtSeals' => ['none']]));
        $this->assertFalse($strategy->hasSeal(['over05HtSeals' => null]));
    }
}

<?php

namespace Tests\Unit;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use PHPUnit\Framework\TestCase;

class AgainstOneGoalStrategyTest extends TestCase
{
    public function test_it_chooses_the_less_likely_one_goal_score(): void
    {
        $choice = (new AgainstOneGoalStrategy())->choice([
            'homeGoalsAverage' => 1.8,
            'awayGoalsAverage' => 0.9,
        ]);

        $this->assertSame('0-1', $choice['score']);
        $this->assertSame(1.8, $choice['counterAttackAverage']);
    }

    public function test_the_balanced_profile_requires_confirmation_from_all_pre_match_signals(): void
    {
        $strategy = new AgainstOneGoalStrategy();
        $row = [
            'homeGoalsAverage' => 1.8,
            'awayGoalsAverage' => 1.0,
            'over25Probability' => 64,
            'bttsProbability' => 62,
            'combinedGoalsAverage' => 3.0,
        ];

        $this->assertTrue($strategy->matchesProfile($row, 'balanced'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'bttsProbability' => 59], 'balanced'));
        $this->assertFalse($strategy->matchesProfile([...$row, 'combinedGoalsAverage' => null], 'balanced'));
    }

    public function test_the_strong_profile_rejects_an_ambiguous_score_choice(): void
    {
        $this->assertFalse((new AgainstOneGoalStrategy())->matchesProfile([
            'homeGoalsAverage' => 1.30,
            'awayGoalsAverage' => 1.25,
            'over25Probability' => 75,
            'bttsProbability' => 70,
            'combinedGoalsAverage' => 3.3,
        ], 'strong'));
    }

    public function test_it_can_choose_the_less_likely_two_goal_score(): void
    {
        $choice = (new AgainstTwoGoalsStrategy())->choice([
            'homeGoalsAverage' => 1.8,
            'awayGoalsAverage' => 0.9,
        ]);

        $this->assertSame('0-2', $choice['score']);
    }

    public function test_it_can_choose_the_less_likely_three_one_score(): void
    {
        $choice = (new AgainstThreeOneStrategy())->choice([
            'homeGoalsAverage' => 0.9,
            'awayGoalsAverage' => 1.8,
        ]);

        $this->assertSame('3-1', $choice['score']);
    }
}

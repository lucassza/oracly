<?php

namespace Tests\Unit;

use App\Oracly\Services\PunterOver05HtStrategy;
use PHPUnit\Framework\TestCase;

class PunterOver05HtStrategyTest extends TestCase
{
    public function test_matches_odd_profile_applies_the_cutoff(): void
    {
        $strategy = new PunterOver05HtStrategy;

        $this->assertTrue($strategy->matchesOddProfile(['oddOver05Ht' => 1.25], 'strong'));
        $this->assertFalse($strategy->matchesOddProfile(['oddOver05Ht' => 1.35], 'strong'));
        $this->assertTrue($strategy->matchesOddProfile(['oddOver05Ht' => 1.35], 'balanced'));
        $this->assertTrue($strategy->matchesOddProfile(['oddOver05Ht' => 1.55], 'baseline'));
        $this->assertFalse($strategy->matchesOddProfile(['oddOver05Ht' => 1.65], 'baseline'));
    }

    public function test_matches_odd_profile_is_false_without_a_valid_odd(): void
    {
        $strategy = new PunterOver05HtStrategy;

        $this->assertFalse($strategy->matchesOddProfile(['oddOver05Ht' => null], 'baseline'));
        $this->assertFalse($strategy->matchesOddProfile([], 'baseline'));
        $this->assertFalse($strategy->matchesOddProfile(['oddOver05Ht' => 0], 'baseline'));
        $this->assertFalse($strategy->matchesOddProfile(['oddOver05Ht' => 1.0], 'baseline'));
        $this->assertFalse($strategy->matchesOddProfile(['oddOver05Ht' => 1.25], 'inexistente'));
    }

    public function test_punter_recommends_reads_the_flag(): void
    {
        $strategy = new PunterOver05HtStrategy;

        $this->assertTrue($strategy->punterRecommends(['punterFlagsOver05Ht' => true]));
        $this->assertFalse($strategy->punterRecommends(['punterFlagsOver05Ht' => false]));
        $this->assertFalse($strategy->punterRecommends([]));
    }

    public function test_result_reads_the_column(): void
    {
        $strategy = new PunterOver05HtStrategy;

        $this->assertSame('green', $strategy->result(['resultOver05Ht' => 'green']));
        $this->assertSame('red', $strategy->result(['resultOver05Ht' => 'red']));
        $this->assertNull($strategy->result([]));
    }
}

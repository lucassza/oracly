<?php

namespace Tests\Unit;

use App\Oracly\Support\BrasiliaDate;
use PHPUnit\Framework\TestCase;

class BrasiliaDateTest extends TestCase
{
    public function test_hour_label_groups_kickoffs_by_whole_hour(): void
    {
        $this->assertSame('15:00', BrasiliaDate::hourLabelFromKickoff('2026-08-31T18:00:00.000Z'));
        $this->assertSame('15:00', BrasiliaDate::hourLabelFromKickoff('2026-08-31T18:15:00.000Z'));
        $this->assertSame('15:00', BrasiliaDate::hourLabelFromKickoff('2026-08-31T18:45:00.000Z'));
        $this->assertSame('16:00', BrasiliaDate::hourLabelFromKickoff('2026-08-31T19:30:00.000Z'));
    }

    public function test_the_kickoff_time_is_the_real_time_not_the_hour_bucket(): void
    {
        // 17:30 UTC = 14:30 em Brasília. O bucket arredonda para 14:00 e serve só às abas;
        // numa coluna de horário ele engana, que foi o defeito que motivou este helper.
        $this->assertSame('14:30', BrasiliaDate::timeFromKickoff('2026-09-11 17:30:00'));
        $this->assertSame('14:00', BrasiliaDate::hourLabelFromKickoff('2026-09-11 17:30:00'));
        $this->assertSame('12:45', BrasiliaDate::timeFromKickoff('2026-09-11T15:45:00+00:00'));
        $this->assertNull(BrasiliaDate::timeFromKickoff(null));
    }
}

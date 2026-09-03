<?php

namespace Tests\Unit\Punter;

use App\Oracly\Punter\ValueNormalizer;
use PHPUnit\Framework\TestCase;

class ValueNormalizerTest extends TestCase
{
    public function test_numeric_accepts_comma_and_dot_decimals(): void
    {
        $this->assertSame(1.25, ValueNormalizer::numeric('1,25'));
        $this->assertSame(0.9345794392523364, ValueNormalizer::numeric('0.9345794392523364'));
        $this->assertNull(ValueNormalizer::numeric(''));
        $this->assertNull(ValueNormalizer::numeric(null));
    }

    public function test_percent_strips_the_percent_sign(): void
    {
        $this->assertSame(57.6, ValueNormalizer::percent('57,6%'));
        $this->assertSame(64.9, ValueNormalizer::percent('64,9%'));
    }

    public function test_int_rounds_a_float_looking_string(): void
    {
        $this->assertSame(5, ValueNormalizer::int('5.0'));
        $this->assertNull(ValueNormalizer::int(''));
    }

    public function test_bool01_reads_numeric_flags(): void
    {
        $this->assertTrue(ValueNormalizer::bool01('1.0'));
        $this->assertTrue(ValueNormalizer::bool01('1'));
        $this->assertFalse(ValueNormalizer::bool01('0.0'));
        $this->assertFalse(ValueNormalizer::bool01('0'));
        $this->assertNull(ValueNormalizer::bool01(''));
    }

    public function test_score_parses_the_x_separated_placar(): void
    {
        $this->assertSame(['home' => 2, 'away' => 0], ValueNormalizer::score('2 x 0'));
        $this->assertSame(['home' => 0, 'away' => 1], ValueNormalizer::score('0 x 1'));
    }

    public function test_score_is_null_for_a_pending_match(): void
    {
        $this->assertNull(ValueNormalizer::score('jogo ainda não ocorreu'));
        $this->assertNull(ValueNormalizer::score(''));
        $this->assertNull(ValueNormalizer::score(null));
    }

    public function test_date_br_parses_day_first_without_leading_zeros(): void
    {
        $this->assertSame('2023-01-28', ValueNormalizer::dateBr('28/01/2023'));
        $this->assertSame('2026-04-09', ValueNormalizer::dateBr('9/4/2026'));
        $this->assertNull(ValueNormalizer::dateBr(''));
    }

    public function test_datetime_br_to_utc_reads_brasilia_time_with_dot_or_slash_separators(): void
    {
        $parsed = ValueNormalizer::datetimeBrToUtc('27.08.2024 00:10:00');
        $this->assertNotNull($parsed);
        $this->assertSame('2024-08-27T03:10:00+00:00', $parsed->utc()->toIso8601String());
    }

    public function test_datetime_br_to_utc_is_null_for_a_pending_match(): void
    {
        $this->assertNull(ValueNormalizer::datetimeBrToUtc('jogo ainda não ocorreu'));
    }

    public function test_text_trims_and_nulls_out_blank_values(): void
    {
        $this->assertSame('Tranquilo', ValueNormalizer::text('  Tranquilo  '));
        $this->assertNull(ValueNormalizer::text(''));
        $this->assertNull(ValueNormalizer::text('   '));
    }
}

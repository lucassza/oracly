<?php

namespace App\Oracly\Support;

final class X7
{
    /**
     * @param  array<string, mixed>  $match
     */
    public static function pred(array $match, string $key): ?float
    {
        $value = data_get($match, "statistics.additional.x7Predictions.{$key}.pred");

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $match
     */
    public static function odd(array $match, string $key): ?float
    {
        $value = data_get($match, "statistics.additional.x7Predictions.{$key}.oj");

        return is_numeric($value) ? (float) $value : null;
    }
}

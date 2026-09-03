<?php

namespace App\Oracly\Punter;

use Carbon\CarbonImmutable;

/**
 * Converte os valores crus do CSV do Google Sheets (decimais com vírgula ou ponto,
 * "2 x 0", "jogo ainda não ocorreu", ✔/X, datas dd.mm.yyyy/dd/mm/yyyy e serial Excel)
 * para os tipos declarados em config/punter.php.
 */
final class ValueNormalizer
{
    private const PENDING_MARKERS = ['jogo ainda não ocorreu', 'jogo ainda nao ocorreu'];

    public static function normalize(string $type, ?string $raw): mixed
    {
        return match ($type) {
            'date' => self::dateBr($raw),
            'datetime_br' => self::datetimeBrToUtc($raw),
            'int' => self::int($raw),
            'numeric' => self::numeric($raw),
            'percent' => self::percent($raw),
            'bool01' => self::bool01($raw),
            'score' => self::score($raw),
            default => self::text($raw),
        };
    }

    public static function text(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function numeric(?string $raw): ?float
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_contains($trimmed, ',') && ! str_contains($trimmed, '.')
            ? str_replace(',', '.', $trimmed)
            : str_replace(',', '', $trimmed);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    public static function percent(?string $raw): ?float
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        return self::numeric(str_replace('%', '', $trimmed));
    }

    public static function int(?string $raw): ?int
    {
        $value = self::numeric($raw);

        return $value === null ? null : (int) round($value);
    }

    public static function bool01(?string $raw): ?bool
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        $value = self::numeric($trimmed);
        if ($value !== null) {
            return abs($value) > 0.0001;
        }

        return in_array(strtolower($trimmed), ['true', 'sim', 'yes', '✔'], true);
    }

    /** @return array{home: int, away: int}|null */
    public static function score(?string $raw): ?array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '' || in_array(mb_strtolower($trimmed), self::PENDING_MARKERS, true)) {
            return null;
        }

        if (! preg_match('/^(\d+)\s*[x\-]\s*(\d+)$/u', $trimmed, $m)) {
            return null;
        }

        return ['home' => (int) $m[1], 'away' => (int) $m[2]];
    }

    public static function dateBr(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $trimmed, $m)) {
            return self::safeDate((int) $m[3], (int) $m[2], (int) $m[1])?->toDateString();
        }

        if (is_numeric($trimmed)) {
            return self::excelSerialToDateTime((float) $trimmed)?->toDateString();
        }

        return null;
    }

    public static function datetimeBrToUtc(?string $raw): ?CarbonImmutable
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '' || in_array(mb_strtolower($trimmed), self::PENDING_MARKERS, true)) {
            return null;
        }

        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $m)) {
            return self::safeDate((int) $m[3], (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0));
        }

        if (is_numeric($trimmed)) {
            return self::excelSerialToDateTime((float) $trimmed);
        }

        return null;
    }

    private static function safeDate(int $year, int $month, int $day, int $hour = 0, int $minute = 0, int $second = 0): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function excelSerialToDateTime(float $serial): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'America/Sao_Paulo')->addSeconds((int) round($serial * 86400));
        } catch (\Throwable) {
            return null;
        }
    }
}

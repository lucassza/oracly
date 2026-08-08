<?php

namespace App\Oracly\Support;

final class BrasiliaDate
{
    public static function today(): string
    {
        return now('America/Sao_Paulo')->toDateString();
    }

    public static function fromKickoff(?string $kickoffAt): ?string
    {
        if (! $kickoffAt) {
            return null;
        }

        $timestamp = strtotime($kickoffAt);
        if ($timestamp === false) {
            return null;
        }

        // Brasilia fixed UTC-3 (no DST since 2019) — same as Node store.
        return gmdate('Y-m-d', $timestamp - 3 * 60 * 60);
    }

    public static function shift(string $date, int $days): string
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d', $date, 'America/Sao_Paulo')
            ->startOfDay()
            ->addDays($days)
            ->toDateString();
    }
}

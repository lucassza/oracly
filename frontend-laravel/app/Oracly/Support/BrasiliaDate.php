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

    /** Hour bucket label for filters (e.g. 15:15 → 15:00). */
    public static function hourLabelFromKickoff(string $kickoffAt): string
    {
        return \Carbon\Carbon::parse($kickoffAt)->timezone('America/Sao_Paulo')->format('H:00');
    }

    /**
     * Horário real do pontapé inicial em Brasília (ex.: 14:30).
     *
     * Existe porque hourLabelFromKickoff() é BUCKET, para as abas de hora, e mostrá-lo numa
     * coluna de horário engana: um jogo às 14:30 aparecia como 14:00. Toda tela que exibe
     * horário deve usar este, e reservar o bucket para agrupar.
     */
    public static function timeFromKickoff(?string $kickoffAt): ?string
    {
        return $kickoffAt ? \Carbon\Carbon::parse($kickoffAt)->timezone('America/Sao_Paulo')->format('H:i') : null;
    }
}

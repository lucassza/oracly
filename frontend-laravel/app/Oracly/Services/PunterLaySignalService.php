<?php

namespace App\Oracly\Services;

use App\Oracly\Support\PunterDb;

/**
 * Lê punter.lay_signals (Lay 2x2 / Lay 0x1) — já vem apurado pelo próprio Punter
 * (check_result = green/red), então não há Strategy/Poisson aqui: a linha inteira já é
 * o pick e o resultado.
 */
final class PunterLaySignalService
{
    /**
     * Sinais cujo kickoff cai no dia Brasília pedido — inclui pendentes e já apurados.
     *
     * @return list<array<string, mixed>>
     */
    public function forDate(string $dateBrasilia): array
    {
        // Brasília fixa UTC-3 (sem horário de verão desde 2019) — mesmo padrão de MatchSnapshotRepository.
        $startUtc = $dateBrasilia.'T03:00:00.000Z';
        $endUtc = date('Y-m-d', strtotime($dateBrasilia.' +1 day')).'T03:00:00.000Z';

        return $this->normalize(
            PunterDb::connection()->table('lay_signals')
                ->where('kickoff_at', '>=', $startUtc)
                ->where('kickoff_at', '<', $endUtc)
                ->orderBy('kickoff_at')
                ->get()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 5000): array
    {
        return $this->normalize(
            PunterDb::connection()->table('lay_signals')
                ->where('settled', true)
                ->orderByDesc('kickoff_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalize($rows): array
    {
        return $rows->map(fn ($row): array => [
            'matchKey' => (string) $row->match_key,
            'radar' => (string) $row->radar,
            'bet' => $row->radar === 'lay_2x2' ? 'LAY 2x2' : 'LAY 0x1',
            'kickoffAt' => (string) $row->kickoff_at,
            'country' => (string) ($row->country ?? ''),
            'competition' => (string) ($row->league ?? ''),
            'homeTeam' => (string) ($row->home_team ?? ''),
            'awayTeam' => (string) ($row->away_team ?? ''),
            'oddHome' => $row->odd_home !== null ? (float) $row->odd_home : null,
            'oddAway' => $row->odd_away !== null ? (float) $row->odd_away : null,
            'flashscoreUrl' => $row->flashscore_url,
            'ftHome' => $row->ft_home,
            'ftAway' => $row->ft_away,
            'htHome' => $row->ht_home,
            'htAway' => $row->ht_away,
            'settled' => (bool) $row->settled,
            'checkResult' => $row->check_result,
            'diagnosis' => $row->diagnosis,
            'hit' => $row->check_result === null ? null : $row->check_result === 'green',
        ])->all();
    }
}

<?php

namespace App\Oracly\Services;

use App\Oracly\Support\PunterDb;

/**
 * Normaliza punter.match_history (apurado) e punter.panel_fixtures (futuro) para o
 * mesmo formato de linha, consumido por PunterLayCasaForaStrategy. As duas tabelas não
 * têm horário de kickoff estruturado — só data — por isso não há agrupamento por hora
 * aqui como existe para o SokkerPRO/lay_signals.
 */
final class PunterMatchPickService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 20000): array
    {
        $rows = PunterDb::connection()->table('match_history')
            ->select([
                'match_key', 'data_hora_jogo', 'home_name', 'away_name', 'campeonato',
                'odds_ft_1', 'odds_ft_x', 'odds_ft_2', 'lay_fora', 'lay_casa',
                'gour_lay_fora', 'gour_lay_casa',
            ])
            ->whereNotNull('data_hora_jogo')
            ->orderBy('data_hora_jogo')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row): array => [
            'matchKey' => (string) $row->match_key,
            'matchDate' => (string) $row->data_hora_jogo,
            'homeTeam' => (string) $row->home_name,
            'awayTeam' => (string) $row->away_name,
            'competition' => (string) $row->campeonato,
            'oddHome' => $row->odds_ft_1 !== null ? (float) $row->odds_ft_1 : null,
            'oddDraw' => $row->odds_ft_x !== null ? (float) $row->odds_ft_x : null,
            'oddAway' => $row->odds_ft_2 !== null ? (float) $row->odds_ft_2 : null,
            'punterFlagsLayFora' => $row->lay_fora === 'Lay Fora',
            'punterFlagsLayCasa' => $row->lay_casa === 'Lay Casa',
            'resultLayFora' => $row->gour_lay_fora !== null ? strtolower($row->gour_lay_fora) : null,
            'resultLayCasa' => $row->gour_lay_casa !== null ? strtolower($row->gour_lay_casa) : null,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function upcoming(string $dateBrasilia): array
    {
        $rows = PunterDb::connection()->table('panel_fixtures')
            ->select([
                'match_date', 'match_label', 'home_team', 'away_team', 'league',
                'opening_odd_home', 'opening_odd_draw', 'opening_odd_away', 'match_odds_tendency',
            ])
            ->whereDate('match_date', $dateBrasilia)
            ->orderBy('match_label')
            ->get();

        return $rows->map(fn ($row): array => [
            'matchKey' => (string) $row->match_date.'|'.$row->home_team.'|'.$row->away_team,
            'matchDate' => (string) $row->match_date,
            'matchLabel' => (string) $row->match_label,
            'homeTeam' => (string) $row->home_team,
            'awayTeam' => (string) $row->away_team,
            'competition' => (string) $row->league,
            'oddHome' => $row->opening_odd_home !== null ? (float) $row->opening_odd_home : null,
            'oddDraw' => $row->opening_odd_draw !== null ? (float) $row->opening_odd_draw : null,
            'oddAway' => $row->opening_odd_away !== null ? (float) $row->opening_odd_away : null,
            'punterFlagsLayFora' => str_contains((string) $row->match_odds_tendency, 'Lay Fora'),
            'punterFlagsLayCasa' => str_contains((string) $row->match_odds_tendency, 'Lay Casa'),
            'resultLayFora' => null,
            'resultLayCasa' => null,
        ])->all();
    }
}

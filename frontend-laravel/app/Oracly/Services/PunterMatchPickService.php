<?php

namespace App\Oracly\Services;

use App\Oracly\Support\PunterDb;

/**
 * Normaliza punter.match_history (apurado) e punter.panel_fixtures (futuro) para o
 * mesmo formato de linha, consumido por PunterLayCasaForaStrategy e pelas 4 estratégias
 * Poisson (AgainstOneGoalStrategy e subclasses) que já existiam para o SokkerPRO — essas
 * não mudam nada, só passam a ser alimentadas com médias de gols calculadas aqui. As duas
 * tabelas não têm horário de kickoff estruturado — só data — por isso não há agrupamento
 * por hora aqui como existe para o SokkerPRO/lay_signals.
 */
final class PunterMatchPickService
{
    /** Quantos jogos anteriores (mesmo time, mesma competição) entram na média de forma. */
    private const FORM_LOOKBACK_MATCHES = 10;

    /** Amostra mínima de jogos anteriores para considerar a média confiável. */
    private const FORM_MIN_SAMPLE = 3;

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
                'media_gols_total_casa', 'media_gols_total_visitante', 'resultado_ft',
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
            'homeGoalsAverage' => $row->media_gols_total_casa !== null ? (float) $row->media_gols_total_casa : null,
            'awayGoalsAverage' => $row->media_gols_total_visitante !== null ? (float) $row->media_gols_total_visitante : null,
            'finalScore' => $this->cleanScore($row->resultado_ft),
        ])->all();
    }

    /**
     * Média de gols do time nos últimos jogos (mesma competição, só jogos ANTERIORES a
     * $beforeDate — sem vazamento) — usada para os picks de placar exato em jogos futuros,
     * já que panel_fixtures não traz média de gols pronta como match_history traz.
     * Times com nome igual em competições diferentes (ex.: "River Plate" existe na
     * Argentina, no Uruguai e na Libertadores) são disambiguados exigindo a mesma
     * competição do jogo futuro.
     */
    public function teamAverageGoals(string $team, string $competition, string $beforeDate): ?float
    {
        $rows = PunterDb::connection()->table('match_history')
            ->select(['home_name', 'away_name', 'home_goal_count', 'away_goal_count'])
            ->where('campeonato', $competition)
            ->where('data_hora_jogo', '<', $beforeDate)
            ->where(function ($query) use ($team): void {
                $query->where('home_name', $team)->orWhere('away_name', $team);
            })
            ->orderByDesc('data_hora_jogo')
            ->limit(self::FORM_LOOKBACK_MATCHES)
            ->get();

        $goals = $rows
            ->map(fn ($row) => $row->home_name === $team ? $row->home_goal_count : $row->away_goal_count)
            ->filter(fn ($goals) => $goals !== null);

        return $goals->count() >= self::FORM_MIN_SAMPLE ? round($goals->avg(), 4) : null;
    }

    /**
     * @return array{home: ?float, away: ?float}
     */
    public function formGoalsAverage(string $homeTeam, string $awayTeam, string $competition, string $beforeDate): array
    {
        return [
            'home' => $this->teamAverageGoals($homeTeam, $competition, $beforeDate),
            'away' => $this->teamAverageGoals($awayTeam, $competition, $beforeDate),
        ];
    }

    private function cleanScore(?string $raw): ?string
    {
        return $raw !== null && preg_match('/^\d+-\d+$/', $raw) === 1 ? $raw : null;
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

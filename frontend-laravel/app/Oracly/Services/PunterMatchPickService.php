<?php

namespace App\Oracly\Services;

use App\Oracly\Support\PunterDb;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Normaliza punter.match_history (apurado) e punter.panel_fixtures (futuro) para o
 * mesmo formato de linha, consumido por PunterLayCasaForaStrategy e pelas 4 estratégias
 * Poisson (AgainstOneGoalStrategy e subclasses) que já existiam para o SokkerPRO — essas
 * não mudam nada, só passam a ser alimentadas com médias de gols calculadas aqui.
 * match_history (apurado) não tem horário estruturado — só data — mas panel_fixtures
 * (futuro) traz o horário embutido no texto de `match_label`, exposto aqui como
 * `kickoffAt` (ver parseKickoffAt()).
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
                'gour_lay_fora', 'gour_lay_casa', 'gour_ht_lay_fora', 'gour_ht_lay_casa',
                'media_gols_total_casa', 'media_gols_total_visitante', 'resultado_ft',
                'ht_goals_team_a', 'ht_goals_team_b',
                'odds_1st_half_over05', 'odds_1st_half_under05', 'tendencia_over_ht', 'gour_over_05_ht',
                'gour_over_2_5_ft',
                'home_goal_count', 'away_goal_count', 'odds_btts_yes', 'odds_btts_no',
                'odds_ft_over25', 'odds_ft_under25', 'odds_ft_under05', 'odds_ft_over05',
                'odds_ft_over15', 'odds_ft_under15',
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
            'resultHtLayFora' => $row->gour_ht_lay_fora !== null ? strtolower($row->gour_ht_lay_fora) : null,
            'resultHtLayCasa' => $row->gour_ht_lay_casa !== null ? strtolower($row->gour_ht_lay_casa) : null,
            'homeGoalsAverage' => $row->media_gols_total_casa !== null ? (float) $row->media_gols_total_casa : null,
            'awayGoalsAverage' => $row->media_gols_total_visitante !== null ? (float) $row->media_gols_total_visitante : null,
            'finalScore' => $this->cleanScore($row->resultado_ft),
            'htScore' => $row->ht_goals_team_a !== null && $row->ht_goals_team_b !== null
                ? ((int) $row->ht_goals_team_a).'-'.((int) $row->ht_goals_team_b)
                : null,
            'oddOver05Ht' => $row->odds_1st_half_over05 !== null ? (float) $row->odds_1st_half_over05 : null,
            'oddUnder05Ht' => $row->odds_1st_half_under05 !== null ? (float) $row->odds_1st_half_under05 : null,
            'resultOver25' => $row->gour_over_2_5_ft !== null ? strtolower($row->gour_over_2_5_ft) : null,
            'punterFlagsOver05Ht' => ! empty($row->tendencia_over_ht),
            'resultOver05Ht' => $row->gour_over_05_ht !== null ? strtolower($row->gour_over_05_ht) : null,
            'homeGoals' => $row->home_goal_count !== null ? (int) $row->home_goal_count : null,
            'awayGoals' => $row->away_goal_count !== null ? (int) $row->away_goal_count : null,
            'oddBttsYes' => $row->odds_btts_yes !== null ? (float) $row->odds_btts_yes : null,
            'oddBttsNo' => $row->odds_btts_no !== null ? (float) $row->odds_btts_no : null,
            'oddOver25' => $row->odds_ft_over25 !== null ? (float) $row->odds_ft_over25 : null,
            'oddUnder25' => $row->odds_ft_under25 !== null ? (float) $row->odds_ft_under25 : null,
            'oddUnder05' => $row->odds_ft_under05 !== null ? (float) $row->odds_ft_under05 : null,
            'oddOver05' => $row->odds_ft_over05 !== null ? (float) $row->odds_ft_over05 : null,
            'oddOver15' => $row->odds_ft_over15 !== null ? (float) $row->odds_ft_over15 : null,
            'oddUnder15' => $row->odds_ft_under15 !== null ? (float) $row->odds_ft_under15 : null,
        ])->all();
    }

    /**
     * Placar final de partidas identificadas por data + mandante + visitante.
     *
     * Serve para apurar cotações registradas a partir da lista do dia: panel_fixtures e
     * match_history não compartilham chave, mas vêm do mesmo painel e usam os mesmos nomes.
     * Partida ainda não apurada simplesmente não aparece no retorno.
     *
     * As odds de 1X2 vêm junto porque o LAY da goleada precisa saber quem era o favorito para
     * apurar. São as do match_history, não as de abertura em que a odd foi registrada; nos
     * perfis da goleada o favorito é claro demais para trocar de lado entre uma e outra.
     *
     * @param list<array{date: string, home: string, away: string}> $matches
     * @return array<string, array{homeGoals: int, awayGoals: int, oddHome: ?float, oddAway: ?float}> chave "data|mandante|visitante"
     */
    public function finalScores(array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        $rows = PunterDb::connection()->table('match_history')
            ->select(['data_hora_jogo', 'home_name', 'away_name', 'home_goal_count', 'away_goal_count', 'odds_ft_1', 'odds_ft_2'])
            ->whereIn('data_hora_jogo', array_values(array_unique(array_column($matches, 'date'))))
            ->whereIn('home_name', array_values(array_unique(array_column($matches, 'home'))))
            ->whereNotNull('home_goal_count')
            ->whereNotNull('away_goal_count')
            ->get();

        $scores = [];
        foreach ($rows as $row) {
            $scores[$row->data_hora_jogo.'|'.$row->home_name.'|'.$row->away_name] = [
                'homeGoals' => (int) $row->home_goal_count,
                'awayGoals' => (int) $row->away_goal_count,
                'oddHome' => $row->odds_ft_1 !== null ? (float) $row->odds_ft_1 : null,
                'oddAway' => $row->odds_ft_2 !== null ? (float) $row->odds_ft_2 : null,
            ];
        }

        return $scores;
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
                'ht_tendency', 'opening_odd_btts', 'goals_tendency', 'opening_odd_over25_ft',
                'opening_odd_over15_ft',
            ])
            ->whereDate('match_date', $dateBrasilia)
            ->orderBy('match_label')
            ->get();

        return $rows->map(fn ($row): array => [
            'matchKey' => (string) $row->match_date.'|'.$row->home_team.'|'.$row->away_team,
            'matchDate' => (string) $row->match_date,
            'matchLabel' => (string) $row->match_label,
            'kickoffAt' => $this->parseKickoffAt((string) $row->match_date, (string) $row->match_label),
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
            'oddOver05Ht' => null,
            'punterFlagsOver05Ht' => ! empty($row->ht_tendency),
            'resultOver05Ht' => null,
            // panel_fixtures só traz o lado "sim" do BTTS, e de ABERTURA. Sem o lado "não" não
            // dá pra tirar a margem, então a probabilidade daqui sai na escala crua — ver
            // AgainstFavouriteCleanSheetStrategy::bttsProbability().
            'oddBttsYes' => $row->opening_odd_btts !== null ? (float) $row->opening_odd_btts : null,
            'oddBttsNo' => null,
            'punterFlagsGoals' => $row->goals_tendency !== null ? (string) $row->goals_tendency : null,
            // Mesmo caso do BTTS: só o lado over, de abertura. AgainstTwoTwoStrategy tira a margem
            // pela média medida no histórico (MarketPoisson::OVER25_OVERROUND).
            'oddOver25' => $row->opening_odd_over25_ft !== null ? (float) $row->opening_odd_over25_ft : null,
            'oddUnder25' => null,
            // Idem: só o over 1,5 de abertura. Over15ValueStrategy tira a margem por faixa de odd.
            'oddOver15' => $row->opening_odd_over15_ft !== null ? (float) $row->opening_odd_over15_ft : null,
            'oddUnder15' => null,
            'homeGoals' => null,
            'awayGoals' => null,
        ])->all();
    }

    /**
     * panel_fixtures não tem coluna de horário estruturada, mas `match_label` traz o
     * horário embutido no texto (ex.: "30/08 22:20 Deportivo Cali x Atlético Bucaramanga",
     * já em horário de Brasília, mesma convenção do resto do painel Punter). `match_date`
     * garante o ano correto (o label só tem DD/MM). Linhas malformadas (label vazio, sem
     * horário reconhecível, data inválida) voltam null — o chamador cai de volta pra exibir
     * só a data.
     *
     * Devolve em UTC (não em horário local "cru") porque todo o resto do painel — inclusive
     * BrasiliaDate::hourLabelFromKickoff() e a própria view Blade — assume que `kickoffAt`
     * é um instante absoluto e sempre reconverte pra America/Sao_Paulo na exibição (mesma
     * convenção de lay_signals.kickoff_at, que já vem em UTC do Postgres). Guardar a hora
     * "crua" aqui faria esse -03:00 ser aplicado de novo, atrasando o horário exibido em 3h.
     */
    private function parseKickoffAt(string $matchDate, string $matchLabel): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate) !== 1) {
            return null;
        }
        // Hora/minuto validados (00-23 / 00-59) porque Carbon::createFromFormat não rejeita
        // valores fora de faixa — ele só "estoura" pro dia seguinte silenciosamente.
        if (preg_match('#^(\d{2})/(\d{2})\s+([01]\d|2[0-3]):([0-5]\d)#', $matchLabel, $m) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i', $matchDate.' '.$m[3].':'.$m[4], 'America/Sao_Paulo')
                ->utc()
                ->toDateTimeString();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}

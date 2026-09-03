<?php

namespace App\Filament\Pages;

use App\Oracly\Services\AgainstOneGoalStrategy;
use App\Oracly\Services\AgainstThreeGoalsStrategy;
use App\Oracly\Services\AgainstThreeOneStrategy;
use App\Oracly\Services\AgainstTwoGoalsStrategy;
use App\Oracly\Services\PunterLayCasaForaStrategy;
use App\Oracly\Services\PunterLaySignalService;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Picks LAY a partir dos dados Punter (schema Postgres `punter`), em paralelo à Lista LAY
 * do SokkerPRO (`DailyLayList`) — não a substitui, os universos de jogos se sobrepõem pouco.
 *
 * Três mercados, cada um com dois modos (lista do dia / histórico apurado):
 *  - LAY 2x2 / LAY 0x1: punter.lay_signals já vem apurado pelo próprio Punter (check_result),
 *    sem Strategy nossa — a linha inteira já é o pick.
 *  - LAY Casa / LAY Fora: critério próprio (PunterLayCasaForaStrategy, odd do favorito) sobre
 *    punter.match_history (apurado) / punter.panel_fixtures (futuro, sem resultado ainda).
 *  - LAY Placar Exato (0x1..3x0): reaproveita as 4 estratégias Poisson que já existiam para o
 *    SokkerPRO (AgainstOneGoalStrategy e subclasses, sem alteração), alimentadas com médias de
 *    gols do Punter — media_gols_total_casa/visitante no histórico, e uma média móvel dos
 *    últimos 10 jogos (mesma competição) calculada por PunterMatchPickService::teamAverageGoals()
 *    para os jogos futuros, já que panel_fixtures não traz essa média pronta.
 */
class PunterLayList extends Page
{
    private const HISTORY_PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Lista LAY Punter';

    protected static ?string $title = 'Lista LAY — Punter';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.punter-lay-list';

    public string $date = '';

    public string $market = 'lay_2x2_0x1';

    public string $mode = 'upcoming';

    public string $radarFilter = 'all';

    public string $profileFilter = 'balanced';

    public string $hourFilter = 'all';

    public string $historyFrom = '';

    public string $historyTo = '';

    public string $historyCompetitionFilter = 'all';

    public int $historyPage = 1;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $historyRows = [];

    /** @var array<string, string> */
    public const MARKET_OPTIONS = [
        'lay_2x2_0x1' => 'LAY 2x2 / LAY 0x1',
        'lay_casa_fora' => 'LAY Casa / LAY Fora',
        'lay_scores' => 'LAY Placar Exato',
    ];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const RADAR_OPTIONS = [
        'all' => 'Todos',
        'lay_2x2' => 'LAY 2x2',
        'lay_0x1' => 'LAY 0x1',
    ];

    /** @var array<string, string> */
    public const PROFILE_OPTIONS = [
        'baseline' => 'Baseline (odd favorito < 1,80)',
        'balanced' => 'Balanced (odd favorito < 1,50)',
        'strong' => 'Strong (odd favorito < 1,30)',
    ];

    /**
     * Só se aplica ao histórico de LAY Casa/Fora e LAY Placar Exato — a escolha do lado/placar
     * não muda, só o momento em que o resultado é conferido (mesmos dados: GouR HT vs GouR FT,
     * ht_goals_team_a/b vs resultado_ft). lay_2x2/lay_0x1 não tem essa distinção — o Punter já
     * apura check_result num único momento (FT), sem HT correspondente para essas duas apostas.
     *
     * @var array<string, string>
     */
    public const PERIOD_OPTIONS = [
        'ft' => 'Apurar no FT',
        'ht' => 'Apurar no HT',
    ];

    public string $periodFilter = 'ft';

    /**
     * Filtro opcional — quando ligado, some com tudo que não for rank 1/2/3 da hora (só
     * lay_2x2_0x1, o único mercado com hora de verdade). Padrão desligado: a lista mostra
     * tudo, o rank só vira badge (medimos: cortar pra top-3 dá só +0,5pp de assertividade
     * — 95,5%→96,0% — perdendo 37% do volume, então não vale ser o padrão).
     */
    public bool $onlyTopOfHour = false;

    /**
     * As 4 sub-estratégias do LAY Placar Exato juntas dão só 79,65% de assertividade
     * conjunta por partida (uma das 4 errar já derruba a partida inteira). `against1`
     * (0x1/1x0) é sempre a excluída — é a mais fraca (79,7%→88,3% ao tirar ela), e
     * testamos alternativas (tirar dinamicamente a de maior risco por partida, tirar só
     * metade do against1) que sempre deram pior resultado que essa combinação fixa, então
     * não é configurável pelo usuário — o "melhor 3" já vem indicado.
     *
     * @var list<string>
     */
    private const EXACT_SCORE_STRATEGY_KEYS = ['against2', 'against31', 'against3'];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function previousDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, -1);
        if ($this->mode === 'upcoming') {
            $this->reload();
        }
    }

    public function nextDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, 1);
        if ($this->mode === 'upcoming') {
            $this->reload();
        }
    }

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
    }

    public function setMarket(string $value): void
    {
        if (! array_key_exists($value, self::MARKET_OPTIONS)) {
            return;
        }
        $this->market = $value;
        $this->resetHistoryPage();
        $this->hourFilter = 'all';
        $this->historyCompetitionFilter = 'all';
        $this->reload();
    }

    public function setMode(string $value): void
    {
        if (! array_key_exists($value, self::MODE_OPTIONS)) {
            return;
        }
        $this->mode = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setRadarFilter(string $value): void
    {
        if (! array_key_exists($value, self::RADAR_OPTIONS)) {
            return;
        }
        $this->radarFilter = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setProfileFilter(string $value): void
    {
        if (! array_key_exists($value, self::PROFILE_OPTIONS)) {
            return;
        }
        $this->profileFilter = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setPeriodFilter(string $value): void
    {
        if (! array_key_exists($value, self::PERIOD_OPTIONS)) {
            return;
        }
        $this->periodFilter = $value;
        $this->resetHistoryPage();
        $this->reload();
    }

    public function setHourFilter(string $hour): void
    {
        $this->hourFilter = $hour === 'all' || in_array($hour, $this->hours, true) ? $hour : 'all';
    }

    public function toggleOnlyTopOfHour(): void
    {
        $this->onlyTopOfHour = ! $this->onlyTopOfHour;
        $this->resetHistoryPage();
    }

    public function updatedHistoryFrom(): void
    {
        $this->resetHistoryPage();
    }

    public function updatedHistoryTo(): void
    {
        $this->resetHistoryPage();
    }

    public function updatedHistoryCompetitionFilter(): void
    {
        $this->resetHistoryPage();
    }

    public function previousHistoryPage(): void
    {
        $this->historyPage = max(1, $this->historyPage - 1);
    }

    public function nextHistoryPage(): void
    {
        $this->historyPage = min($this->historyPagination['lastPage'], $this->historyPage + 1);
    }

    public function reload(): void
    {
        try {
            $this->rows = $this->mode === 'upcoming' ? $this->buildUpcomingRows() : [];
            $this->historyRows = $this->mode === 'history' ? $this->buildHistoryRows() : [];

            if ($this->mode === 'upcoming' && $this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->historyRows = [];
            Notification::make()->title('Erro ao montar a lista LAY Punter')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return list<string> */
    public function getHoursProperty(): array
    {
        if ($this->market !== 'lay_2x2_0x1') {
            return [];
        }

        $hours = array_values(array_unique(array_map(
            fn (array $row): string => BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']),
            $this->rows,
        )));
        sort($hours);

        return $hours;
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredRowsProperty(): array
    {
        $rows = $this->rows;

        if ($this->market === 'lay_2x2_0x1' && $this->hourFilter !== 'all') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']) === $this->hourFilter,
            ));
        }

        if ($this->market === 'lay_2x2_0x1' && $this->onlyTopOfHour) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($row['rank'] ?? 99) <= 3));
        }

        return $rows;
    }

    /** Cards agrupados por partida, como a Lista LAY do SokkerPRO — uma ou mais apostas por jogo.
     * @return list<array<string, mixed>> */
    public function getGroupedRowsProperty(): array
    {
        $groups = $this->groupByFixture($this->filteredRows);

        usort($groups, fn (array $a, array $b): int => strcmp(
            (string) ($a['kickoffAt'] ?? $a['dateBrasilia']),
            (string) ($b['kickoffAt'] ?? $b['dateBrasilia']),
        ) ?: strcmp($a['homeTeam'], $b['homeTeam']));

        return $groups;
    }

    /** @return list<array<string, mixed>> */
    public function getFilteredHistoryRowsProperty(): array
    {
        $onlyTop = $this->market === 'lay_2x2_0x1' && $this->onlyTopOfHour;

        return array_values(array_filter($this->historyRows, function (array $row) use ($onlyTop): bool {
            $date = $row['dateBrasilia'];

            return ($this->historyFrom === '' || $date >= $this->historyFrom)
                && ($this->historyTo === '' || $date <= $this->historyTo)
                && ($this->historyCompetitionFilter === 'all' || $row['competitionLabel'] === $this->historyCompetitionFilter)
                && (! $onlyTop || ($row['rank'] ?? 99) <= 3);
        }));
    }

    /** Cards agrupados por partida (histórico) — mesma lógica da lista do dia.
     * @return list<array<string, mixed>> */
    public function getGroupedHistoryRowsProperty(): array
    {
        $groups = $this->groupByFixture($this->filteredHistoryRows);

        usort($groups, fn (array $a, array $b): int => strcmp(
            (string) ($b['kickoffAt'] ?? $b['dateBrasilia']),
            (string) ($a['kickoffAt'] ?? $a['dateBrasilia']),
        ));

        return $groups;
    }

    /** @return array{page: int, lastPage: int, total: int, from: int, to: int} */
    public function getHistoryPaginationProperty(): array
    {
        $total = count($this->groupedHistoryRows);
        $lastPage = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page = min(max(1, $this->historyPage), $lastPage);

        return [
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
            'from' => $total === 0 ? 0 : (($page - 1) * self::HISTORY_PER_PAGE) + 1,
            'to' => min($page * self::HISTORY_PER_PAGE, $total),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getPagedHistoryRowsProperty(): array
    {
        return array_slice($this->groupedHistoryRows, ($this->historyPagination['page'] - 1) * self::HISTORY_PER_PAGE, self::HISTORY_PER_PAGE);
    }

    /**
     * Agrupa linhas (uma por aposta) em cards por partida — mesmo padrão da DailyLayList
     * do SokkerPRO. `fixtureKey` identifica a partida (independe de qual aposta/radar);
     * cada aposta some dentro de `bets` com seu texto de apoio (`betMeta`) já formatado.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupByFixture(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['fixtureKey'];
            $groups[$key] ??= [
                'fixtureKey' => $key,
                'dateBrasilia' => $row['dateBrasilia'],
                'kickoffAt' => $row['kickoffAt'] ?? null,
                'matchLabel' => $row['matchLabel'] ?? null,
                'homeTeam' => $row['homeTeam'],
                'awayTeam' => $row['awayTeam'],
                'country' => $row['country'] ?? null,
                'competition' => $row['competition'] ?? null,
                'ftHome' => $row['ftHome'] ?? null,
                'ftAway' => $row['ftAway'] ?? null,
                'htHome' => $row['htHome'] ?? null,
                'htAway' => $row['htAway'] ?? null,
                'bets' => [],
            ];
            $groups[$key]['bets'][] = [
                'bet' => $row['bet'],
                'betMeta' => $row['betMeta'],
                'hit' => $row['hit'] ?? null,
                'rank' => $row['rank'] ?? null,
            ];
        }

        return array_values($groups);
    }

    /**
     * "Melhor da hora" — mesmo critério de segurança de `DailyLayList::topThreeByHour()`
     * (SokkerPRO), mas sem cortar a lista: agrupa por hora Brasília e numera o rank (1, 2, 3...)
     * de cada pick dentro da hora, do mais seguro pro menos seguro. Mantém TODAS as linhas — o
     * badge 👑/🔥/● (`opportunity-rank-badge`) só aparece nos ranks 1/2/3, os demais ficam sem
     * selo mas continuam na lista. Testamos cortar pra top-3 antes: a assertividade mudava só
     * +0,5pp (95,5%→96,0%) cortando 37% do volume, não valia a perda de visibilidade.
     * Só faz sentido para lay_2x2_0x1 — é o único mercado Punter com horário de kickoff real;
     * match_history/panel_fixtures só têm data.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): float  $metric  Menor valor = pick mais seguro.
     * @return list<array<string, mixed>>
     */
    private function rankWithinHour(array $rows, callable $metric): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $bucket = Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('Y-m-d H');
            $groups[$bucket][] = $row;
        }

        $selected = [];
        foreach ($groups as $group) {
            usort($group, fn (array $a, array $b): int => $metric($a) <=> $metric($b));
            foreach ($group as $i => $row) {
                $row['rank'] = $i + 1;
                $selected[] = $row;
            }
        }

        return $selected;
    }

    private static function bestOdd(array $row): float
    {
        $odds = array_values(array_filter(
            [$row['oddHome'] ?? null, $row['oddAway'] ?? null],
            fn (?float $v): bool => $v !== null,
        ));

        return $odds === [] ? INF : min($odds);
    }

    /** @return array<string, string> */
    public function getHistoryCompetitionsProperty(): array
    {
        $competitions = [];
        foreach ($this->historyRows as $row) {
            $competitions[$row['competitionLabel']] = $row['competitionLabel'];
        }
        asort($competitions);

        return $competitions;
    }

    /** @return array{entries: int, wins: int, reds: int, hitRate: ?float} */
    public function getHistoryStatsProperty(): array
    {
        $entries = count($this->filteredHistoryRows);
        $wins = count(array_filter($this->filteredHistoryRows, fn (array $row): bool => $row['hit'] === true));

        return [
            'entries' => $entries,
            'wins' => $wins,
            'reds' => $entries - $wins,
            'hitRate' => $entries > 0 ? ($wins / $entries) * 100 : null,
        ];
    }

    /**
     * Só pro LAY Placar Exato: agrupa por partida e mede quantas partidas tiveram TODAS as
     * apostas selecionadas acertando ao mesmo tempo — a métrica que interessa quando se
     * combina várias sub-estratégias na mesma partida (uma errar já derruba o conjunto).
     *
     * @return array{entries: int, wins: int, reds: int, hitRate: ?float}
     */
    public function getJointAccuracyStatsProperty(): array
    {
        if ($this->market !== 'lay_scores') {
            return ['entries' => 0, 'wins' => 0, 'reds' => 0, 'hitRate' => null];
        }

        $byFixture = [];
        foreach ($this->filteredHistoryRows as $row) {
            $byFixture[$row['fixtureKey']][] = $row['hit'];
        }

        $entries = count($byFixture);
        $wins = 0;
        foreach ($byFixture as $hits) {
            if (! in_array(false, $hits, true)) {
                $wins++;
            }
        }

        return [
            'entries' => $entries,
            'wins' => $wins,
            'reds' => $entries - $wins,
            'hitRate' => $entries > 0 ? ($wins / $entries) * 100 : null,
        ];
    }

    /** @return list<array{strategy: string, entries: int, wins: int, reds: int, hitRate: ?float}> */
    public function getHistoryStrategyStatsProperty(): array
    {
        $strategies = [];

        foreach ($this->filteredHistoryRows as $row) {
            $strategy = (string) $row['bet'];
            $strategies[$strategy] ??= ['strategy' => $strategy, 'entries' => 0, 'wins' => 0, 'reds' => 0, 'hitRate' => null];
            $strategies[$strategy]['entries']++;
            if ($row['hit'] === true) {
                $strategies[$strategy]['wins']++;
            } else {
                $strategies[$strategy]['reds']++;
            }
        }

        foreach ($strategies as &$strategy) {
            $strategy['hitRate'] = $strategy['entries'] > 0 ? ($strategy['wins'] / $strategy['entries']) * 100 : null;
        }

        usort($strategies, fn (array $a, array $b): int => ($b['hitRate'] ?? 0) <=> ($a['hitRate'] ?? 0) ?: strcmp($a['strategy'], $b['strategy']));

        return $strategies;
    }

    /** @return list<array{date: string, label: string, entries: int, wins: int, reds: int, hitRate: ?float}> */
    public function getHistoryChartDaysProperty(): array
    {
        $days = [];

        foreach ($this->filteredHistoryRows as $row) {
            $date = $row['dateBrasilia'];
            $days[$date] ??= ['date' => $date, 'label' => Carbon::parse($date)->format('d/m'), 'entries' => 0, 'wins' => 0, 'reds' => 0, 'hitRate' => null];
            $days[$date]['entries']++;
            if ($row['hit'] === true) {
                $days[$date]['wins']++;
            } else {
                $days[$date]['reds']++;
            }
        }

        ksort($days);
        $days = array_values($days);

        foreach ($days as &$day) {
            $day['hitRate'] = $day['entries'] > 0 ? ($day['wins'] / $day['entries']) * 100 : null;
        }

        return array_slice($days, -30);
    }

    /** @return list<array<string, mixed>> */
    private function buildUpcomingRows(): array
    {
        if ($this->market === 'lay_2x2_0x1') {
            $rows = app(PunterLaySignalService::class)->forDate($this->date);
            if ($this->radarFilter !== 'all') {
                $rows = array_values(array_filter($rows, fn (array $r): bool => $r['radar'] === $this->radarFilter));
            }

            foreach ($rows as &$row) {
                $row['fixtureKey'] = $row['matchKey'];
                $row['dateBrasilia'] = BrasiliaDate::fromKickoff($row['kickoffAt']);
                $row['betMeta'] = self::formatOddPair($row['oddHome'], $row['oddAway']);
            }
            unset($row);

            $rows = $this->rankWithinHour($rows, fn (array $r): float => self::bestOdd($r));

            usort($rows, fn (array $a, array $b): int => strcmp($a['kickoffAt'], $b['kickoffAt'])
                ?: (($a['oddHome'] ?? INF) <=> ($b['oddHome'] ?? INF)));

            return $rows;
        }

        if ($this->market === 'lay_scores') {
            $picks = app(PunterMatchPickService::class);
            $rows = [];

            foreach ($picks->upcoming($this->date) as $row) {
                $form = $picks->formGoalsAverage($row['homeTeam'], $row['awayTeam'], $row['competition'], $row['matchDate']);
                if ($form['home'] === null || $form['away'] === null) {
                    continue;
                }
                $featureRow = ['homeGoalsAverage' => $form['home'], 'awayGoalsAverage' => $form['away']];

                foreach (self::exactScoreStrategies() as $strategy) {
                    $choice = $strategy->choice($featureRow);
                    if ($choice === null) {
                        continue;
                    }

                    $rows[] = [
                        'matchKey' => $row['matchKey'].'|'.$choice['score'],
                        'fixtureKey' => $row['matchKey'],
                        'matchLabel' => $row['matchLabel'],
                        'dateBrasilia' => $row['matchDate'],
                        'homeTeam' => $row['homeTeam'],
                        'awayTeam' => $row['awayTeam'],
                        'competition' => $row['competition'],
                        'bet' => 'LAY '.str_replace('-', 'x', (string) $choice['score']),
                        'probability' => $choice['probability'],
                        'betMeta' => 'prob. '.number_format($choice['probability'] * 100, 1).'%',
                    ];
                }
            }

            usort($rows, fn (array $a, array $b): int => $a['probability'] <=> $b['probability']);

            return $rows;
        }

        $strategy = app(PunterLayCasaForaStrategy::class);
        $rows = [];

        foreach (app(PunterMatchPickService::class)->upcoming($this->date) as $row) {
            $choice = $strategy->choice($row);
            if ($choice === null || ! $strategy->matchesProfile($row, $this->profileFilter)) {
                continue;
            }

            $rows[] = [
                'matchKey' => $row['matchKey'],
                'fixtureKey' => $row['matchKey'],
                'matchLabel' => $row['matchLabel'],
                'dateBrasilia' => $row['matchDate'],
                'homeTeam' => $row['homeTeam'],
                'awayTeam' => $row['awayTeam'],
                'competition' => $row['competition'],
                'bet' => $choice['side'] === 'fora' ? 'LAY FORA' : 'LAY CASA',
                'favoriteOdd' => $choice['favoriteOdd'],
                'underdogOdd' => $choice['underdogOdd'],
                'punterAgrees' => $choice['punterAgrees'],
                'betMeta' => self::formatFavoriteOdd($choice['favoriteOdd'], $choice['punterAgrees']),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['favoriteOdd'] <=> $b['favoriteOdd']);

        return $rows;
    }

    private static function formatOddPair(?float $home, ?float $away): string
    {
        return ($home !== null ? number_format($home, 2) : '—').' / '.($away !== null ? number_format($away, 2) : '—');
    }

    private static function formatFavoriteOdd(float $favoriteOdd, bool $punterAgrees): string
    {
        return 'odd '.number_format($favoriteOdd, 2).($punterAgrees ? ' · Punter concorda' : '');
    }

    /**
     * As 3 sub-estratégias fixas do LAY Placar Exato — ver EXACT_SCORE_STRATEGY_KEYS.
     *
     * @return array<string, AgainstOneGoalStrategy>
     */
    private static function exactScoreStrategies(): array
    {
        $all = [
            'against1' => new AgainstOneGoalStrategy,
            'against2' => new AgainstTwoGoalsStrategy,
            'against31' => new AgainstThreeOneStrategy,
            'against3' => new AgainstThreeGoalsStrategy,
        ];

        return array_intersect_key($all, array_flip(self::EXACT_SCORE_STRATEGY_KEYS));
    }

    /** @return list<array<string, mixed>> */
    private function buildHistoryRows(): array
    {
        if ($this->market === 'lay_2x2_0x1') {
            return OraclyCache::remember(OraclyCache::key('punter-lay-list:history:lay_signals:v4'), function (): array {
                $rows = app(PunterLaySignalService::class)->history(5000);
                foreach ($rows as &$row) {
                    $row['dateBrasilia'] = BrasiliaDate::fromKickoff($row['kickoffAt']);
                    $row['competitionLabel'] = trim(($row['country'] ?? '').' · '.($row['competition'] ?? ''), ' ·');
                    $row['fixtureKey'] = $row['matchKey'];
                    $row['betMeta'] = self::formatOddPair($row['oddHome'], $row['oddAway']);
                }
                unset($row);

                return $this->rankWithinHour($rows, fn (array $r): float => self::bestOdd($r));
            }, 300);
        }

        if ($this->market === 'lay_scores') {
            return OraclyCache::remember(OraclyCache::key('punter-lay-list:history:lay_scores:v5:'.$this->periodFilter), function (): array {
                $rows = [];

                foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
                    $targetScore = $this->periodFilter === 'ht' ? $row['htScore'] : $row['finalScore'];
                    if ($targetScore === null || $row['homeGoalsAverage'] === null || $row['awayGoalsAverage'] === null) {
                        continue;
                    }

                    foreach (self::exactScoreStrategies() as $strategy) {
                        $choice = $strategy->choice($row);
                        if ($choice === null) {
                            continue;
                        }

                        $rows[] = [
                            'matchKey' => $row['matchKey'].'|'.$choice['score'],
                            'fixtureKey' => $row['matchKey'],
                            'dateBrasilia' => $row['matchDate'],
                            'homeTeam' => $row['homeTeam'],
                            'awayTeam' => $row['awayTeam'],
                            'competition' => $row['competition'],
                            'competitionLabel' => str_replace('_', ' ', (string) $row['competition']),
                            'bet' => 'LAY '.str_replace('-', 'x', (string) $choice['score']),
                            'probability' => $choice['probability'],
                            'betMeta' => 'prob. '.number_format($choice['probability'] * 100, 1).'%',
                            'hit' => $targetScore !== $choice['score'],
                        ];
                    }
                }

                return $rows;
            }, 300);
        }

        return OraclyCache::remember(OraclyCache::key('punter-lay-list:history:lay_casa_fora:v3:'.$this->profileFilter.':'.$this->periodFilter), function (): array {
            $strategy = app(PunterLayCasaForaStrategy::class);
            $rows = [];

            foreach (app(PunterMatchPickService::class)->history(20000) as $row) {
                $choice = $strategy->choice($row);
                if ($choice === null || ! $strategy->matchesProfile($row, $this->profileFilter)) {
                    continue;
                }
                $result = $strategy->result($row, $choice['side'], $this->periodFilter);
                if ($result === null) {
                    continue;
                }

                $rows[] = [
                    'matchKey' => $row['matchKey'],
                    'fixtureKey' => $row['matchKey'],
                    'dateBrasilia' => $row['matchDate'],
                    'homeTeam' => $row['homeTeam'],
                    'awayTeam' => $row['awayTeam'],
                    'competition' => $row['competition'],
                    'competitionLabel' => str_replace('_', ' ', (string) $row['competition']),
                    'bet' => $choice['side'] === 'fora' ? 'LAY FORA' : 'LAY CASA',
                    'favoriteOdd' => $choice['favoriteOdd'],
                    'punterAgrees' => $choice['punterAgrees'],
                    'betMeta' => self::formatFavoriteOdd($choice['favoriteOdd'], $choice['punterAgrees']),
                    'hit' => $result === 'green',
                ];
            }

            return $rows;
        }, 300);
    }

    private function resetHistoryPage(): void
    {
        $this->historyPage = 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->visible(fn (): bool => $this->mode === 'upcoming')->action(fn () => $this->previousDay()),
            Action::make('reload')->label('Recarregar banco')->action(fn () => $this->refresh()),
            Action::make('next')->label('Próximo dia')->visible(fn (): bool => $this->mode === 'upcoming')->action(fn () => $this->nextDay()),
        ];
    }
}

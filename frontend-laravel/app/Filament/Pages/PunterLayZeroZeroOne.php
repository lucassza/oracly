<?php

namespace App\Filament\Pages;

use App\Models\LayOddQuote;
use App\Oracly\Services\NilNilZeroOneLayStrategy;
use App\Oracly\Services\PunterLaySignalService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\HourRank;
use App\Oracly\Support\LayCycleSimulator;
use App\Oracly\Support\LayPricing;
use App\Oracly\Support\OraclyCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * LAY 0x0 + LAY 0x1 no mesmo jogo, sobre os sinais LAY 0x1 do Punter (punter.lay_signals).
 *
 * Tela de operação da estratégia de NilNilZeroOneLayStrategy: lista do dia com as duas stakes
 * sugeridas e o registro das odds de lay da exchange (LayOddQuote, uma linha por perna), e um
 * histórico que simula os ciclos de juros compostos com as odds registradas quando existem.
 * O resultado vem do próprio Punter (ft_home/ft_away), não de match_history.
 */
class PunterLayZeroZeroOne extends Page
{
    /** Chaves de LayOddQuote::strategy, uma por perna. */
    public const QUOTE_STRATEGIES = ['nil' => 'lay_00_01_nil', 'one' => 'lay_00_01_one'];

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const CYCLE_OPTIONS = [
        'double' => 'Ciclo até dobrar',
        '5' => 'Ciclo de 5 greens',
        '8' => 'Ciclo de 8 greens',
        '10' => 'Ciclo de 10 greens',
    ];

    private const HISTORY_DISPLAY_LIMIT = 100;

    private const QUOTES_DISPLAYED = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'LAY 0x0 + 0x1 Punter';

    protected static ?string $title = 'LAY 0x0 + 0x1 — Punter';

    protected static ?string $slug = 'punter-lay-00-01';

    protected static string|UnitEnum|null $navigationGroup = 'Operação diária';

    /** Logo abaixo da Lista LAY Punter (2), de onde vêm os sinais. */
    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.punter-lay-zero-zero-one';

    public string $mode = 'upcoming';

    public string $date = '';

    public string $rule = 'best_of_hour';

    public string $cycle = 'double';

    public string $historyYear = '';

    /** Responsabilidade por perna, em R$. É também a base do ciclo. */
    public string $liability = '100';

    /** Odds do simulador para os jogos sem odd registrada. */
    public string $simOddNil = '';

    public string $simOddOne = '';

    /** @var list<array<string, mixed>> Sinais LAY 0x1 do dia, com rank da hora. */
    public array $rows = [];

    /**
     * Odds digitadas por jogo, indexadas pelo hash da matchKey (a chave crua quebra o wire:model).
     *
     * @var array<string, array{nil?: string|float|null, one?: string|float|null}>
     */
    public array $quoteInputs = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->historyYear = substr($this->date, 0, 4);
        $this->simOddNil = (string) NilNilZeroOneLayStrategy::DEFAULT_ODDS['nil'];
        $this->simOddOne = (string) NilNilZeroOneLayStrategy::DEFAULT_ODDS['one'];
        $this->reload();
    }

    public function reload(): void
    {
        try {
            $this->rows = $this->mode === 'upcoming' ? $this->buildUpcomingRows() : [];
            $this->prefillQuoteInputs();
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao ler os sinais do Punter')->body($e->getMessage())->danger()->send();
        }
    }

    public function refresh(): void
    {
        OraclyCache::forgetPrefix();
        $this->reload();
    }

    public function setMode(string $value): void
    {
        if (array_key_exists($value, self::MODE_OPTIONS)) {
            $this->mode = $value;
            $this->reload();
        }
    }

    public function setRule(string $value): void
    {
        if (array_key_exists($value, HourRank::RULES)) {
            $this->rule = $value;
        }
    }

    public function setCycle(string $value): void
    {
        if (array_key_exists($value, self::CYCLE_OPTIONS)) {
            $this->cycle = $value;
        }
    }

    public function setHistoryYear(string $value): void
    {
        if (array_key_exists($value, $this->yearOptions)) {
            $this->historyYear = $value;
        }
    }

    public function previousDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, -1);
        $this->reload();
    }

    public function nextDay(): void
    {
        $this->date = BrasiliaDate::shift($this->date, 1);
        $this->reload();
    }

    public static function quoteHash(string $matchKey): string
    {
        return substr(md5($matchKey), 0, 16);
    }

    /** @return array{nil: float, one: float} */
    public function getSimOddsProperty(): array
    {
        return [
            'nil' => self::parseOdd($this->simOddNil) ?? NilNilZeroOneLayStrategy::DEFAULT_ODDS['nil'],
            'one' => self::parseOdd($this->simOddOne) ?? NilNilZeroOneLayStrategy::DEFAULT_ODDS['one'],
        ];
    }

    public function getLiabilityValueProperty(): float
    {
        $value = str_replace(',', '.', trim($this->liability));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : 100.0;
    }

    /**
     * Sinais LAY 0x1 do dia escolhidos pela regra, com as stakes para a responsabilidade atual.
     * Usa a odd digitada quando há; senão a padrão.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpcomingRowsProperty(): array
    {
        $probabilities = $this->referenceProbabilities;

        return array_map(function (array $row) use ($probabilities): array {
            $hash = self::quoteHash($row['matchKey']);
            $typed = [
                'nil' => self::parseOdd($this->quoteInputs[$hash]['nil'] ?? null),
                'one' => self::parseOdd($this->quoteInputs[$hash]['one'] ?? null),
            ];
            $odds = [
                'nil' => $typed['nil'] ?? NilNilZeroOneLayStrategy::DEFAULT_ODDS['nil'],
                'one' => $typed['one'] ?? NilNilZeroOneLayStrategy::DEFAULT_ODDS['one'],
            ];

            return [...$row,
                'quoteHash' => $hash,
                'odds' => $odds,
                'oddsTyped' => $typed['nil'] !== null && $typed['one'] !== null,
                'stakes' => NilNilZeroOneLayStrategy::stakes($this->liabilityValue, $odds['nil'], $odds['one']),
                'verdict' => $typed['nil'] !== null && $typed['one'] !== null && $probabilities['nil'] !== null
                    ? NilNilZeroOneLayStrategy::verdict($probabilities['nil'], $probabilities['one'], $odds['nil'], $odds['one'])
                    : null,
            ];
        }, HourRank::select($this->rows, $this->rule));
    }

    /**
     * Sinais LAY 0x1 apurados, o banco inteiro. Computed para não ir no payload do Livewire.
     *
     * @return list<array<string, mixed>>
     */
    public function getHistoryRowsProperty(): array
    {
        try {
            return OraclyCache::remember(OraclyCache::key('punter-lay-00-01:history:v1'), function (): array {
                $rows = [];
                foreach (app(PunterLaySignalService::class)->history(60000) as $row) {
                    $result = NilNilZeroOneLayStrategy::result($row['ftHome'] ?? null, $row['ftAway'] ?? null);
                    if (($row['radar'] ?? null) !== 'lay_0x1' || $result === null || empty($row['kickoffAt'])) {
                        continue;
                    }
                    $rows[] = [...$row,
                        'dateBrasilia' => BrasiliaDate::fromKickoff($row['kickoffAt']),
                        'competitionLabel' => trim(($row['country'] ?? '').' · '.($row['competition'] ?? ''), ' ·'),
                        'result' => $result,
                    ];
                }

                return $rows;
            }, 300);
        } catch (\Throwable $e) {
            Notification::make()->title('Erro ao ler o histórico do Punter')->body($e->getMessage())->danger()->send();

            return [];
        }
    }

    /** @return array<string, string> */
    public function getYearOptionsProperty(): array
    {
        $years = array_unique(array_map(fn (array $row): string => substr((string) $row['dateBrasilia'], 0, 4), $this->historyRows));
        rsort($years);
        $options = [];
        foreach ($years as $year) {
            $options[$year] = $year;
        }
        $options['all'] = 'Todos os anos';

        return $options;
    }

    /**
     * Histórico escolhido pela regra no ano selecionado, com o retorno de cada entrada. Jogo com as
     * duas odds registradas usa as registradas; os outros, as do simulador.
     *
     * @return list<array<string, mixed>>
     */
    public function getSelectedHistoryProperty(): array
    {
        return $this->selectHistory($this->historyYear);
    }

    /** @return array{entries: int, greens: int, redNil: int, redOne: int, hitRate: ?float, avgReturn: ?float, realOdds: int} */
    public function getHistoryStatsProperty(): array
    {
        return self::stats($this->selectedHistory);
    }

    /** @return array{result: float, maxDrawdown: float, completed: int, broken: int, entries: int, months: array<string, float>} */
    public function getCycleStatsProperty(): array
    {
        return $this->simulate($this->selectedHistory);
    }

    /**
     * Cada ano na regra, nas odds e no ciclo escolhidos — para ver se o resultado se repete.
     *
     * @return list<array<string, mixed>>
     */
    public function getYearStatsProperty(): array
    {
        $years = array_values(array_filter(array_keys($this->yearOptions), fn (string $y): bool => $y !== 'all'));
        sort($years);

        return array_map(function (string $year): array {
            $rows = $this->selectHistory($year);

            return ['year' => $year, ...self::stats($rows), 'cycle' => $this->simulate($rows)];
        }, $years);
    }

    /**
     * Frequência de 0x0 e 0x1 nos sinais da regra, em todos os anos: a chance usada no veredito
     * das odds digitadas.
     *
     * @return array{nil: ?float, one: ?float, entries: int}
     */
    public function getReferenceProbabilitiesProperty(): array
    {
        $rows = HourRank::select($this->historyRows, $this->rule);
        $entries = count($rows);
        if ($entries === 0) {
            return ['nil' => null, 'one' => null, 'entries' => 0];
        }

        return [
            'nil' => count(array_filter($rows, fn (array $r): bool => $r['result'] === 'red_nil')) / $entries,
            'one' => count(array_filter($rows, fn (array $r): bool => $r['result'] === 'red_one')) / $entries,
            'entries' => $entries,
        ];
    }

    /** @return list<array<string, mixed>> Últimas entradas do histórico, da mais recente pra trás. */
    public function getDisplayedHistoryProperty(): array
    {
        return array_slice(array_reverse($this->selectedHistory), 0, self::HISTORY_DISPLAY_LIMIT);
    }

    public function saveQuote(string $hash): void
    {
        $user = Auth::user();
        $row = collect($this->upcomingRows)->firstWhere('quoteHash', $hash);
        $nil = self::parseOdd($this->quoteInputs[$hash]['nil'] ?? null);
        $one = self::parseOdd($this->quoteInputs[$hash]['one'] ?? null);

        if ($user === null || $row === null || $this->mode !== 'upcoming') {
            return;
        }
        if ($nil === null || $one === null) {
            Notification::make()->title('Digite as duas odds de lay')->body('0x0 e 0x1, números maiores que 1, por exemplo 11 e 14,5.')->warning()->send();

            return;
        }

        $probabilities = $this->referenceProbabilities;
        foreach (['nil' => $nil, 'one' => $one] as $leg => $offered) {
            $probability = $probabilities[$leg] === null ? null : $probabilities[$leg] * 100;
            LayOddQuote::updateOrCreate(
                ['user_id' => $user->getAuthIdentifier(), 'strategy' => self::QUOTE_STRATEGIES[$leg], 'match_key' => (string) $row['matchKey']],
                [
                    'match_date' => BrasiliaDate::fromKickoff($row['kickoffAt']),
                    'kickoff_at' => $row['kickoffAt'],
                    'home_team' => (string) $row['homeTeam'],
                    'away_team' => (string) $row['awayTeam'],
                    'competition' => trim(($row['country'] ?? '').' · '.($row['competition'] ?? ''), ' ·') ?: null,
                    'profile' => $this->rule,
                    'probability' => $probability,
                    'fair_odd' => LayPricing::fairOdd($probability),
                    'offered_odd' => $offered,
                ],
            );
        }

        $this->quoteInputs[$hash] = ['nil' => $nil, 'one' => $one];
        Notification::make()->title('Odds registradas')->body($row['homeTeam'].' x '.$row['awayTeam'].' · 0x0 '.number_format($nil, 2, ',', '.').' · 0x1 '.number_format($one, 2, ',', '.'))->success()->send();
    }

    public function deleteQuote(string $hash): void
    {
        $matchKey = collect($this->quotes)->firstWhere('hash', $hash)['matchKey'] ?? null;
        if ($matchKey === null) {
            return;
        }

        LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->whereIn('strategy', array_values(self::QUOTE_STRATEGIES))
            ->where('match_key', $matchKey)
            ->delete();
        $this->prefillQuoteInputs();
    }

    /**
     * Odds registradas pelo usuário, uma entrada por jogo com as duas pernas, apuradas pelo sinal
     * do Punter quando ele já tem placar final.
     *
     * @return list<array<string, mixed>>
     */
    public function getQuotesProperty(): array
    {
        $byMatch = [];
        $legByStrategy = array_flip(self::QUOTE_STRATEGIES);
        $quotes = LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->whereIn('strategy', array_values(self::QUOTE_STRATEGIES))
            ->orderByDesc('match_date')
            ->orderByDesc('id')
            ->get();

        foreach ($quotes as $quote) {
            $byMatch[$quote->match_key] ??= [
                'hash' => self::quoteHash($quote->match_key),
                'matchKey' => $quote->match_key,
                'matchDate' => $quote->match_date->format('Y-m-d'),
                'homeTeam' => $quote->home_team,
                'awayTeam' => $quote->away_team,
                'competition' => $quote->competition,
                'odds' => ['nil' => null, 'one' => null],
            ];
            $byMatch[$quote->match_key]['odds'][$legByStrategy[$quote->strategy]] = $quote->offered_odd;
        }

        $settled = [];
        foreach ($this->historyRows as $row) {
            $settled[$row['matchKey']] = $row;
        }

        return array_values(array_map(function (array $quote) use ($settled): array {
            $signal = $settled[$quote['matchKey']] ?? null;
            $complete = $quote['odds']['nil'] !== null && $quote['odds']['one'] !== null;

            return [...$quote,
                'score' => $signal === null ? null : $signal['ftHome'].'-'.$signal['ftAway'],
                'result' => $signal['result'] ?? null,
                'realizedReturn' => $signal === null || ! $complete ? null
                    : NilNilZeroOneLayStrategy::realizedReturn($signal['result'], $quote['odds']['nil'], $quote['odds']['one']),
            ];
        }, $byMatch));
    }

    /** @return list<array<string, mixed>> */
    public function getRecentQuotesProperty(): array
    {
        return array_slice($this->quotes, 0, self::QUOTES_DISPLAYED);
    }

    /** @return array{count: int, medianNil: ?float, medianOne: ?float, settled: int, reds: int, realizedReturn: ?float} */
    public function getQuoteSummaryProperty(): array
    {
        $quotes = $this->quotes;
        $settled = array_values(array_filter($quotes, fn (array $q): bool => $q['realizedReturn'] !== null));

        return [
            'count' => count($quotes),
            'medianNil' => self::median(array_column(array_column($quotes, 'odds'), 'nil')),
            'medianOne' => self::median(array_column(array_column($quotes, 'odds'), 'one')),
            'settled' => count($settled),
            'reds' => count(array_filter($settled, fn (array $q): bool => $q['result'] !== 'green')),
            'realizedReturn' => $settled === [] ? null : array_sum(array_column($settled, 'realizedReturn')) / count($settled) * 100,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->visible(fn (): bool => $this->mode === 'upcoming')->action(fn () => $this->previousDay()),
            Action::make('reload')->label('Recarregar banco')->action(fn () => $this->refresh()),
            Action::make('next')->label('Próximo dia')->visible(fn (): bool => $this->mode === 'upcoming')->action(fn () => $this->nextDay()),
        ];
    }

    /**
     * Sem type hint no serviço de propósito: os testes injetam um duplo por duck typing
     * (PunterLaySignalService é final).
     *
     * @return list<array<string, mixed>>
     */
    private function buildUpcomingRows(): array
    {
        $rows = array_values(array_filter(
            app(PunterLaySignalService::class)->forDate($this->date),
            fn (array $row): bool => ($row['radar'] ?? null) === 'lay_0x1' && ! empty($row['kickoffAt']),
        ));

        return HourRank::rank($rows, HourRank::bestOdd(...));
    }

    /** @return list<array<string, mixed>> */
    private function selectHistory(string $year): array
    {
        $rows = $year === 'all'
            ? $this->historyRows
            : array_values(array_filter($this->historyRows, fn (array $row): bool => str_starts_with((string) $row['dateBrasilia'], $year)));
        $quoted = $this->quotedOdds;
        $sim = $this->simOdds;

        return array_map(function (array $row) use ($quoted, $sim): array {
            $odds = $quoted[$row['matchKey']] ?? null;

            return [...$row,
                'odds' => $odds ?? $sim,
                'realOdds' => $odds !== null,
                'return' => NilNilZeroOneLayStrategy::realizedReturn($row['result'], ($odds ?? $sim)['nil'], ($odds ?? $sim)['one']),
            ];
        }, HourRank::select($rows, $this->rule));
    }

    /** @return array<string, array{nil: float, one: float}> Jogos com as duas pernas registradas. */
    public function getQuotedOddsProperty(): array
    {
        $odds = [];
        foreach ($this->quotes as $quote) {
            if ($quote['odds']['nil'] !== null && $quote['odds']['one'] !== null) {
                $odds[$quote['matchKey']] = $quote['odds'];
            }
        }

        return $odds;
    }

    /** @param list<array<string, mixed>> $rows */
    private function simulate(array $rows): array
    {
        return LayCycleSimulator::run(
            array_map(fn (array $row): array => ['date' => (string) $row['dateBrasilia'], 'return' => $row['return']], $rows),
            $this->liabilityValue,
            $this->cycle === 'double' ? null : (int) $this->cycle,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{entries: int, greens: int, redNil: int, redOne: int, hitRate: ?float, avgReturn: ?float, realOdds: int}
     */
    private static function stats(array $rows): array
    {
        $entries = count($rows);
        $count = fn (string $result): int => count(array_filter($rows, fn (array $r): bool => $r['result'] === $result));
        $greens = $count('green');

        return [
            'entries' => $entries,
            'greens' => $greens,
            'redNil' => $count('red_nil'),
            'redOne' => $count('red_one'),
            'hitRate' => $entries === 0 ? null : $greens / $entries * 100,
            'avgReturn' => $entries === 0 ? null : array_sum(array_column($rows, 'return')) / $entries * 100,
            'realOdds' => count(array_filter($rows, fn (array $r): bool => $r['realOdds'])),
        ];
    }

    private function prefillQuoteInputs(): void
    {
        $this->quoteInputs = [];
        if ($this->mode !== 'upcoming' || Auth::id() === null) {
            return;
        }

        $legByStrategy = array_flip(self::QUOTE_STRATEGIES);
        $saved = LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->whereIn('strategy', array_values(self::QUOTE_STRATEGIES))
            ->whereIn('match_key', array_column($this->rows, 'matchKey'))
            ->get(['match_key', 'strategy', 'offered_odd']);

        foreach ($saved as $quote) {
            $this->quoteInputs[self::quoteHash($quote->match_key)][$legByStrategy[$quote->strategy]] = (float) $quote->offered_odd;
        }
    }

    /** @param list<float|null> $values */
    private static function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v): bool => $v !== null));
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        return $count % 2 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
    }

    /** Aceita vírgula decimal, que é como o operador digita. */
    private static function parseOdd(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (! is_numeric($value)) {
            return null;
        }
        $odd = (float) $value;

        return $odd > 1.0 && $odd < 1000 ? $odd : null;
    }
}

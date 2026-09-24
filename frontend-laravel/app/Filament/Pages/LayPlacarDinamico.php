<?php

namespace App\Filament\Pages;

use App\Models\LayOddQuote;
use App\Oracly\Services\DynamicScoreLayStrategy;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\HistoryCsv;
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
 * LAY de placar exato com os placares escolhidos por partida (DynamicScoreLayStrategy).
 *
 * Irmã de LayPlacarUnico, mas com até duas pernas por jogo: cada perna tem o próprio placar,
 * a própria odd justa e o próprio registro de odd (LayOddQuote com strategy "lay_dinamico:<placar>",
 * o que mantém o índice único usuário × estratégia × jogo sem migração).
 *
 * A escolha é contra um MODELO de mercado (Poisson das odds de 1X2 e over 2,5), não contra a
 * odd real de placar exato, que não existe em nenhuma base. Por isso o registro de odds é o
 * centro da tela: é ele que diz se a exchange paga o que o modelo supõe.
 *
 * Lista do dia: razões aprendidas no histórico inteiro. Histórico: razões aprendidas só antes
 * de VALIDATION_FROM e aplicadas depois — a tela mostra o número de validação, sem vazamento.
 */
class LayPlacarDinamico extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'LAY Placar Dinâmico';

    protected static ?string $title = 'LAY de placar exato dinâmico';

    protected static string | UnitEnum | null $navigationGroup = 'Operação diária';

    protected static ?int $navigationSort = -6;

    protected string $view = 'filament.pages.lay-placar-dinamico';

    /** Prefixo de LayOddQuote::strategy; o placar casa-fora vem depois dos dois-pontos. */
    public const QUOTE_STRATEGY_PREFIX = 'lay_dinamico:';

    /** Início da validação: razões do histórico da tela são aprendidas só antes disto. */
    public const VALIDATION_FROM = '2025-07-01';

    /** Mesmo corte das outras telas de lay: acima disso o payload do Livewire estoura. */
    private const HISTORY_DISPLAY_LIMIT = 500;

    /** O histórico só muda na sincronização diária. */
    private const CACHE_SECONDS = 21600;

    private const QUOTES_DISPLAYED = 50;

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    /** @var array<string, string> */
    public const PROFILES = DynamicScoreLayStrategy::PROFILES;

    public string $mode = 'upcoming';

    public string $date = '';

    /** 1 placar por jogo: maior volume, maior acerto por partida e maior folga contra o modelo na validação. */
    public string $profile = 'wide';

    public string $hourFilter = 'all';

    /** @var list<array<string, mixed>> Jogos do dia com as pernas de cada perfil. */
    public array $rows = [];

    /**
     * Odd de lay digitada por perna, indexada por legHash().
     *
     * @var array<string, string|float|null>
     */
    public array $quoteInputs = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->reload();
    }

    public function strategy(): DynamicScoreLayStrategy
    {
        return app(DynamicScoreLayStrategy::class);
    }

    public function reload(): void
    {
        try {
            $this->rows = $this->mode === 'upcoming' ? $this->buildUpcomingRows() : [];
            if ($this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
            $this->prefillQuoteInputs();
        } catch (\Throwable $e) {
            $this->rows = [];
            Notification::make()->title('Erro ao ler a base Punter')->body($e->getMessage())->danger()->send();
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

    public function setProfile(string $value): void
    {
        if (array_key_exists($value, self::PROFILES)) {
            $this->profile = $value;
            if ($this->hourFilter !== 'all' && ! in_array($this->hourFilter, $this->hours, true)) {
                $this->hourFilter = 'all';
            }
        }
    }

    public function setHourFilter(string $hour): void
    {
        $this->hourFilter = $hour === 'all' || in_array($hour, $this->hours, true) ? $hour : 'all';
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

    public static function legHash(string $matchKey, string $score): string
    {
        return substr(md5($matchKey.'#'.$score), 0, 16);
    }

    /** @return array<string, float> */
    private function currentRatios(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-dinamico:ratios:v1'), function (): array {
            return $this->strategy()->learnRatios($this->settledRows());
        }, self::CACHE_SECONDS);
    }

    /** @return list<array<string, mixed>> */
    private function settledRows(): array
    {
        return array_values(array_filter(
            app(PunterMatchPickService::class)->history(60000),
            fn (array $row): bool => is_numeric($row['homeGoals'] ?? null) && is_numeric($row['awayGoals'] ?? null),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function buildUpcomingRows(): array
    {
        $strategy = $this->strategy();
        $ratios = $this->currentRatios();
        $rows = [];

        foreach (app(PunterMatchPickService::class)->upcoming($this->date) as $row) {
            $legs = [];
            foreach (array_keys(self::PROFILES) as $profile) {
                $legs[$profile] = $strategy->pick($row, $profile, $ratios);
            }
            if (array_merge(...array_values($legs)) === []) {
                continue;
            }
            $rows[] = [
                'matchKey' => $row['matchKey'],
                'matchDate' => $row['matchDate'],
                'kickoffAt' => $row['kickoffAt'] ?? null,
                'homeTeam' => $row['homeTeam'],
                'awayTeam' => $row['awayTeam'],
                'competition' => $row['competition'] ?? '',
                'favouriteSide' => $strategy->favouriteSide($row),
                'oddHome' => $row['oddHome'],
                'oddAway' => $row['oddAway'],
                'legsByProfile' => $legs,
            ];
        }
        usort($rows, fn (array $a, array $b): int => ($a['kickoffAt'] ?? '') <=> ($b['kickoffAt'] ?? ''));

        return $rows;
    }

    /**
     * Validação sem vazamento: razões aprendidas antes de VALIDATION_FROM, pernas escolhidas e
     * apuradas nos jogos a partir dela. Só os campos que a tela usa, mais recentes primeiro.
     *
     * @return list<array<string, mixed>>
     */
    private function validationHistory(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-dinamico:validation:v1'), function (): array {
            $strategy = $this->strategy();
            $settled = $this->settledRows();
            $ratios = $strategy->learnRatios(array_filter($settled, fn (array $row): bool => $row['matchDate'] < self::VALIDATION_FROM));

            $rows = [];
            foreach ($settled as $row) {
                if ($row['matchDate'] < self::VALIDATION_FROM) {
                    continue;
                }
                $legs = [];
                foreach (array_keys(self::PROFILES) as $profile) {
                    $legs[$profile] = array_map(fn (array $leg): array => [
                        ...$leg, 'result' => $strategy->resultForLeg($row, $leg['score']),
                    ], $strategy->pick($row, $profile, $ratios));
                }
                if (array_merge(...array_values($legs)) === []) {
                    continue;
                }
                $rows[] = [
                    'matchKey' => $row['matchKey'],
                    'matchDate' => $row['matchDate'],
                    'homeTeam' => $row['homeTeam'],
                    'awayTeam' => $row['awayTeam'],
                    'competition' => $row['competition'] ?? '',
                    'favouriteSide' => $strategy->favouriteSide($row),
                    'oddHome' => $row['oddHome'],
                    'oddAway' => $row['oddAway'],
                    'homeGoals' => $row['homeGoals'],
                    'awayGoals' => $row['awayGoals'],
                    'legsByProfile' => $legs,
                ];
            }
            usort($rows, fn (array $a, array $b): int => $b['matchDate'] <=> $a['matchDate']);

            return $rows;
        }, self::CACHE_SECONDS);
    }

    /** @return list<string> */
    public function getHoursProperty(): array
    {
        $hours = [];
        foreach ($this->rows as $row) {
            if (is_string($row['kickoffAt'] ?? null) && $row['kickoffAt'] !== '' && ($row['legsByProfile'][$this->profile] ?? []) !== []) {
                $hours[BrasiliaDate::hourLabelFromKickoff($row['kickoffAt'])] = true;
            }
        }
        $hours = array_keys($hours);
        sort($hours);

        return $hours;
    }

    /** @return array<string, string> */
    public function getHourOptionsProperty(): array
    {
        return ['all' => 'Todos os horários'] + array_combine($this->hours, $this->hours);
    }

    /**
     * Jogos do perfil, cada um com as pernas já precificadas.
     *
     * @return list<array<string, mixed>>
     */
    public function getFilteredRowsProperty(): array
    {
        $source = $this->mode === 'history' ? $this->validationHistory() : $this->rows;
        $rows = [];

        foreach ($source as $row) {
            $legs = $row['legsByProfile'][$this->profile] ?? [];
            if ($legs === []) {
                continue;
            }
            if ($this->mode === 'upcoming' && $this->hourFilter !== 'all'
                && (! is_string($row['kickoffAt'] ?? null) || BrasiliaDate::hourLabelFromKickoff($row['kickoffAt']) !== $this->hourFilter)) {
                continue;
            }

            unset($row['legsByProfile']);
            $row['legs'] = array_map(function (array $leg) use ($row): array {
                $hash = self::legHash((string) $row['matchKey'], $leg['score']);

                return [...$leg,
                    'hash' => $hash,
                    'maxEntryOdd' => LayPricing::maxEntryOdd($leg['fairOdd']),
                    'verdict' => $this->mode === 'upcoming' ? $this->verdict($leg['probability'], $this->quoteInputs[$hash] ?? null) : null,
                ];
            }, $legs);
            $rows[] = $row;

            if ($this->mode === 'history' && count($rows) >= self::HISTORY_DISPLAY_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Números de validação por perfil.
     *
     * @return array<string, array{matches: int, legs: int, legHitRate: ?float, matchHitRate: ?float, modelRatio: ?float}>
     */
    public function getProfileStatsProperty(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-dinamico:stats:v1'), function (): array {
            $history = $this->validationHistory();
            $stats = [];

            foreach (array_keys(self::PROFILES) as $profile) {
                $matches = 0;
                $lost = 0;
                $legs = 0;
                $reds = 0;
                $expected = 0.0;
                foreach ($history as $row) {
                    $picked = $row['legsByProfile'][$profile];
                    if ($picked === []) {
                        continue;
                    }
                    $matches++;
                    $matchLost = false;
                    foreach ($picked as $leg) {
                        $legs++;
                        $red = $leg['result'] === 'red';
                        $reds += $red ? 1 : 0;
                        $matchLost = $matchLost || $red;
                        $expected += $leg['modelProbability'] / 100;
                    }
                    $lost += $matchLost ? 1 : 0;
                }
                $stats[$profile] = [
                    'matches' => $matches,
                    'legs' => $legs,
                    'legHitRate' => $legs === 0 ? null : (1 - $reds / $legs) * 100,
                    'matchHitRate' => $matches === 0 ? null : (1 - $lost / $matches) * 100,
                    'modelRatio' => $expected > 0 ? $reds / $expected : null,
                ];
            }

            return $stats;
        }, self::CACHE_SECONDS);
    }

    /**
     * Mesmo veredito de LayPlacarUnico: contra a odd máxima de entrada da perna.
     *
     * @return array{verdict: 'enter'|'thin'|'skip', ratio: float, expectedReturn: ?float}|null
     */
    public function verdict(?float $probability, mixed $offered): ?array
    {
        $fair = LayPricing::fairOdd($probability);
        $offered = $this->parseOdd($offered);
        if ($fair === null || $offered === null) {
            return null;
        }
        $ratio = $offered / $fair;

        return [
            'verdict' => $ratio <= LayPricing::ENTRY_RATIO ? 'enter' : ($ratio < 1.0 ? 'thin' : 'skip'),
            'ratio' => $ratio,
            'expectedReturn' => LayPricing::expectedReturn($probability, $offered),
        ];
    }

    public function saveQuote(string $hash): void
    {
        $user = Auth::user();
        if ($user === null || $this->mode !== 'upcoming') {
            return;
        }

        foreach ($this->filteredRows as $row) {
            foreach ($row['legs'] as $leg) {
                if ($leg['hash'] !== $hash) {
                    continue;
                }
                $offered = $this->parseOdd($this->quoteInputs[$hash] ?? null);
                if ($offered === null) {
                    Notification::make()->title('Digite a odd de lay da exchange')->body('Um número maior que 1, por exemplo 18,5.')->warning()->send();

                    return;
                }

                LayOddQuote::updateOrCreate(
                    ['user_id' => $user->getAuthIdentifier(), 'strategy' => self::QUOTE_STRATEGY_PREFIX.$leg['score'], 'match_key' => (string) $row['matchKey']],
                    [
                        'match_date' => substr((string) $row['matchDate'], 0, 10),
                        'kickoff_at' => $row['kickoffAt'] ?? null,
                        'home_team' => (string) $row['homeTeam'],
                        'away_team' => (string) $row['awayTeam'],
                        'competition' => $row['competition'] ?: null,
                        'profile' => $this->profile,
                        'probability' => $leg['probability'],
                        'fair_odd' => $leg['fairOdd'],
                        'offered_odd' => $offered,
                    ],
                );

                $this->quoteInputs[$hash] = $offered;
                Notification::make()
                    ->title('Odd registrada')
                    ->body($row['homeTeam'].' x '.$row['awayTeam'].' · lay '.str_replace('-', 'x', $leg['score']).' · '.number_format($offered, 2, ',', '.'))
                    ->success()
                    ->send();

                return;
            }
        }
    }

    public function deleteQuote(int $id): void
    {
        LayOddQuote::query()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->where('strategy', 'like', self::QUOTE_STRATEGY_PREFIX.'%')
            ->delete();
        $this->prefillQuoteInputs();
    }

    /**
     * Cotações do usuário nesta estratégia, apuradas quando o jogo já está no match_history.
     *
     * @return list<array<string, mixed>>
     */
    public function getQuotesProperty(): array
    {
        $quotes = LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->where('strategy', 'like', self::QUOTE_STRATEGY_PREFIX.'%')
            ->orderByDesc('match_date')
            ->orderByDesc('id')
            ->get();

        $pending = $quotes
            ->filter(fn (LayOddQuote $quote): bool => $quote->match_date->format('Y-m-d') <= BrasiliaDate::today())
            ->map(fn (LayOddQuote $quote): array => ['date' => $quote->match_date->format('Y-m-d'), 'home' => $quote->home_team, 'away' => $quote->away_team])
            ->values()
            ->all();
        try {
            $scores = app(PunterMatchPickService::class)->finalScores($pending);
        } catch (\Throwable) {
            $scores = [];
        }

        return $quotes->map(function (LayOddQuote $quote) use ($scores): array {
            $score = $scores[$quote->match_date->format('Y-m-d').'|'.$quote->home_team.'|'.$quote->away_team] ?? null;
            $laid = substr($quote->strategy, strlen(self::QUOTE_STRATEGY_PREFIX));
            $result = $score === null ? null : ($score['homeGoals'].'-'.$score['awayGoals'] === $laid ? 'red' : 'green');

            return [
                'id' => $quote->id,
                'matchDate' => $quote->match_date->format('Y-m-d'),
                'homeTeam' => $quote->home_team,
                'awayTeam' => $quote->away_team,
                'competition' => $quote->competition,
                'score' => $laid,
                'offeredOdd' => $quote->offered_odd,
                'fairOdd' => $quote->fair_odd,
                'probability' => $quote->probability,
                'ratio' => $quote->ratio(),
                'finalScore' => $score,
                'result' => $result,
                'realizedReturn' => $result === null ? null : LayPricing::realizedReturn($result === 'green', $quote->offered_odd),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    public function getRecentQuotesProperty(): array
    {
        return array_slice($this->quotes, 0, self::QUOTES_DISPLAYED);
    }

    /**
     * Se a exchange paga abaixo da justa — mesmo resumo de LayPlacarUnico.
     *
     * @return array{count: int, medianRatio: ?float, enterShare: ?float, settled: int, reds: int, realizedReturn: ?float}
     */
    public function getQuoteSummaryProperty(): array
    {
        $quotes = $this->quotes;
        $ratios = array_values(array_filter(array_column($quotes, 'ratio'), fn ($r): bool => $r !== null));
        sort($ratios);
        $settled = array_values(array_filter($quotes, fn (array $q): bool => $q['result'] !== null));
        $count = count($ratios);

        return [
            'count' => count($quotes),
            'medianRatio' => $count === 0 ? null : ($count % 2 ? $ratios[intdiv($count, 2)] : ($ratios[$count / 2 - 1] + $ratios[$count / 2]) / 2),
            'enterShare' => $count === 0 ? null : count(array_filter($ratios, fn (float $r): bool => $r <= LayPricing::ENTRY_RATIO)) / $count * 100,
            'settled' => count($settled),
            'reds' => count(array_filter($settled, fn (array $q): bool => $q['result'] === 'red')),
            'realizedReturn' => $settled === [] ? null : array_sum(array_column($settled, 'realizedReturn')) / count($settled) * 100,
        ];
    }

    private function prefillQuoteInputs(): void
    {
        $this->quoteInputs = [];
        if ($this->mode !== 'upcoming' || Auth::id() === null) {
            return;
        }

        $saved = LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->where('strategy', 'like', self::QUOTE_STRATEGY_PREFIX.'%')
            ->whereIn('match_key', array_column($this->rows, 'matchKey'))
            ->get(['match_key', 'strategy', 'offered_odd']);

        foreach ($saved as $quote) {
            $score = substr($quote->strategy, strlen(self::QUOTE_STRATEGY_PREFIX));
            $this->quoteInputs[self::legHash((string) $quote->match_key, $score)] = (float) $quote->offered_odd;
        }
    }

    /** Aceita vírgula decimal, que é como o operador digita. */
    private function parseOdd(mixed $value): ?float
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

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $lines = [];
        foreach ($this->filteredRows as $row) {
            foreach ($row['legs'] as $leg) {
                $lines[] = [
                    $row['matchDate'],
                    $row['homeTeam'],
                    $row['awayTeam'],
                    $row['competition'],
                    str_replace('-', 'x', $leg['score']),
                    number_format($leg['probability'], 2, ',', ''),
                    number_format($leg['ratio'], 2, ',', ''),
                    number_format($leg['fairOdd'], 2, ',', ''),
                    ($row['homeGoals'] ?? '—').'-'.($row['awayGoals'] ?? '—'),
                    $leg['result'] ?? '',
                ];
            }
        }

        return HistoryCsv::download(
            'historico-lay-placar-dinamico.csv',
            ['Data', 'Casa', 'Visitante', 'Liga', 'Placar laydado', 'Chance calibrada', 'Real/modelo', 'Odd justa', 'Placar final', 'Resultado'],
            $lines,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prev')->label('Dia anterior')->visible(fn (): bool => $this->mode === 'upcoming')->action('previousDay'),
            Action::make('exportCsv')->label('Exportar CSV')->visible(fn (): bool => $this->mode === 'history')->action('exportCsv'),
            Action::make('reload')->label('Recarregar banco')->action('refresh'),
            Action::make('next')->label('Próximo dia')->visible(fn (): bool => $this->mode === 'upcoming')->action('nextDay'),
        ];
    }
}

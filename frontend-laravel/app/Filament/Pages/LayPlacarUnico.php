<?php

namespace App\Filament\Pages;

use App\Models\LayOddQuote;
use App\Oracly\Services\PunterMatchPickService;
use App\Oracly\Services\SingleScoreLayStrategy;
use App\Oracly\Support\BrasiliaDate;
use App\Oracly\Support\HistoryCsv;
use App\Oracly\Support\HitRateSummary;
use App\Oracly\Support\LayPricing;
use App\Oracly\Support\MarketPoisson;
use App\Oracly\Support\OraclyCache;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Base das telas de lay de UM evento por partida: LayDoisADois (2x2), LayZeroAZero (0x0) e
 * LayGoleada (favorito vence por 4+).
 *
 * Diferente de LayPlacarSeco, que tem duas pernas por jogo e odd justa de coorte, aqui cada jogo
 * tem a própria chance calibrada e portanto a própria odd justa e a própria odd máxima de
 * entrada (LayPricing::ENTRY_RATIO da justa).
 *
 * O centro da tela é o registro da odd de lay da exchange (LayOddQuote). O histórico só tem odd
 * de casa de aposta; se a exchange paga abaixo da justa é uma pergunta que só as cotações
 * registradas respondem, e em poucas centenas de entradas — o lucro levaria dezenas de milhares.
 *
 * Mesmo padrão de LayPlacarSeco: a base concentra a lógica, as subclasses trocam só navegação,
 * textos e a estratégia. A blade é herdada.
 */
abstract class LayPlacarUnico extends Page
{
    protected string $view = 'filament.pages.lay-placar-unico';

    abstract public function strategy(): SingleScoreLayStrategy;

    /** Chave gravada em LayOddQuote::strategy. */
    abstract protected function strategyKey(): string;

    /** Texto de abertura da tela. */
    abstract public function getStrategyDescriptionProperty(): string;

    /** Nota sobre o que a seleção faz e o que ela não faz, embaixo dos perfis. */
    abstract public function getSelectionNoteProperty(): string;

    abstract protected function historyCsvFilename(): string;

    /** Linha acima do título. */
    public function getEyebrowProperty(): string
    {
        return 'Punter · lay de placar único';
    }

    /** Perfil em que a tela abre: o que a validação temporal sustenta, não necessariamente o do meio. */
    protected function defaultProfile(): string
    {
        return 'balanced';
    }

    /** Mesmo corte de LayPlacarSeco: acima disso o payload do Livewire estoura. */
    private const HISTORY_DISPLAY_LIMIT = 500;

    /** Histórico só muda na sincronização diária; o modelo leva ~2s na base inteira. */
    private const HISTORY_CACHE_SECONDS = 21600;

    private const QUOTES_DISPLAYED = 50;

    /** @var array<string, string> */
    public const MODE_OPTIONS = [
        'upcoming' => 'Lista diária',
        'history' => 'Histórico',
    ];

    public string $mode = 'upcoming';

    public string $date = '';

    public string $signalProfile = 'balanced';

    public string $hourFilter = 'all';

    /** @var list<array<string, mixed>> Jogos do dia já avaliados, todos os perfis. */
    public array $rows = [];

    /**
     * Odd de lay digitada por jogo, indexada pelo hash da matchKey — a chave crua tem "|" e
     * espaço, que quebram o caminho do wire:model.
     *
     * @var array<string, string|float|null>
     */
    public array $quoteInputs = [];

    public function mount(): void
    {
        $this->date = BrasiliaDate::today();
        $this->signalProfile = $this->defaultProfile();
        $this->reload();
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

    public function setSignalProfile(string $value): void
    {
        if (array_key_exists($value, $this->strategy()->profiles())) {
            $this->signalProfile = $value;
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

    public static function quoteHash(string $matchKey): string
    {
        return substr(md5($matchKey), 0, 16);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $evaluation = $this->strategy()->evaluate($row);

        return [...$row,
            'probability' => $evaluation['probability'],
            'profiles' => $evaluation['profiles'],
            'favourite' => MarketPoisson::favouriteProbability($row),
            'favouriteSide' => $this->favouriteSide($row),
            'result' => $this->strategy()->result($row),
        ];
    }

    /** @param array<string, mixed> $row */
    private function favouriteSide(array $row): ?string
    {
        $home = is_numeric($row['oddHome'] ?? null) ? (float) $row['oddHome'] : null;
        $away = is_numeric($row['oddAway'] ?? null) ? (float) $row['oddAway'] : null;
        if ($home === null || $away === null || $home === $away) {
            return null;
        }

        return $home < $away ? 'home' : 'away';
    }

    /**
     * Sem type hint no serviço de propósito: os testes injetam um duplo por duck typing
     * (PunterMatchPickService é final). Ver LayPlacarSeco::buildHistoryRows().
     *
     * @return list<array<string, mixed>>
     */
    private function buildUpcomingRows(): array
    {
        $rows = array_map(fn (array $row): array => $this->decorate($row), app(PunterMatchPickService::class)->upcoming($this->date));
        usort($rows, fn (array $a, array $b): int => ($a['kickoffAt'] ?? $a['matchLabel'] ?? '') <=> ($b['kickoffAt'] ?? $b['matchLabel'] ?? ''));

        return $rows;
    }

    /**
     * Base inteira apurada e avaliada, mais recentes primeiro, só com os campos que a tela usa.
     * Não vira propriedade pública: é grande demais para o Livewire serializar.
     *
     * @return list<array<string, mixed>>
     */
    private function settledHistory(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-unico:'.$this->strategyKey().':history:v1'), function (): array {
            $rows = [];
            foreach (app(PunterMatchPickService::class)->history(60000) as $row) {
                $decorated = $this->decorate($row);
                if ($decorated['result'] === null || $decorated['profiles'] === []) {
                    continue;
                }
                $rows[] = [
                    'matchKey' => $row['matchKey'] ?? '',
                    'matchDate' => $row['matchDate'] ?? '',
                    'homeTeam' => $row['homeTeam'] ?? '',
                    'awayTeam' => $row['awayTeam'] ?? '',
                    'competition' => $row['competition'] ?? '',
                    'homeGoals' => $row['homeGoals'],
                    'awayGoals' => $row['awayGoals'],
                    'probability' => $decorated['probability'],
                    'profiles' => $decorated['profiles'],
                    'favourite' => $decorated['favourite'],
                    'favouriteSide' => $decorated['favouriteSide'],
                    'result' => $decorated['result'],
                ];
            }
            usort($rows, fn (array $a, array $b): int => $b['matchDate'] <=> $a['matchDate']);

            return $rows;
        }, self::HISTORY_CACHE_SECONDS);
    }

    /** Horas de Brasília presentes na lista do dia. @return list<string> */
    public function getHoursProperty(): array
    {
        $hours = [];
        foreach ($this->rows as $row) {
            if (is_string($row['kickoffAt'] ?? null) && $row['kickoffAt'] !== '' && in_array($this->signalProfile, $row['profiles'] ?? [], true)) {
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
     * Agregados por perfil, medidos na base INTEIRA. Cacheia só o resumo.
     *
     * @return array<string, array{entries: int, hitRate: ?float, development: ?float, validation: ?float, frequency: ?float, predicted: ?float, fairOdd: ?float, maxEntryOdd: ?float}>
     */
    public function getProfileStatsProperty(): array
    {
        return OraclyCache::remember(OraclyCache::key('lay-unico:'.$this->strategyKey().':stats:v1'), function (): array {
            $history = $this->settledHistory();
            // HitRateSummary::temporal espera ordem ascendente.
            $ascending = array_reverse($history);
            $isHit = fn (array $row): bool => $row['result'] === 'green';
            $stats = [];

            foreach (array_keys($this->strategy()->profiles()) as $profile) {
                $slice = array_values(array_filter($ascending, fn (array $row): bool => in_array($profile, $row['profiles'], true)));
                $summary = HitRateSummary::temporal($slice, $isHit);
                $frequency = $summary['overall']['hitRate'] === null ? null : 100 - $summary['overall']['hitRate'];
                $withModel = array_filter(array_column($slice, 'probability'), fn ($p): bool => $p !== null);
                $fair = LayPricing::fairOdd($frequency);

                $stats[$profile] = [
                    'entries' => $summary['overall']['entries'],
                    'hitRate' => $summary['overall']['hitRate'],
                    'development' => $summary['development']['hitRate'],
                    'validation' => $summary['validation']['hitRate'],
                    'frequency' => $frequency,
                    'predicted' => $withModel === [] ? null : array_sum($withModel) / count($withModel),
                    'fairOdd' => $fair,
                    'maxEntryOdd' => LayPricing::maxEntryOdd($fair),
                ];
            }

            return $stats;
        }, self::HISTORY_CACHE_SECONDS);
    }

    /** @return array{entries: int, hitRate: ?float, development: ?float, validation: ?float, frequency: ?float, predicted: ?float, fairOdd: ?float, maxEntryOdd: ?float} */
    public function getCurrentStatsProperty(): array
    {
        return $this->profileStats[$this->signalProfile]
            ?? ['entries' => 0, 'hitRate' => null, 'development' => null, 'validation' => null, 'frequency' => null, 'predicted' => null, 'fairOdd' => null, 'maxEntryOdd' => null];
    }

    /**
     * Jogos da tela no perfil e horário escolhidos, com preço.
     *
     * Jogo sem chance própria (sem odd de over 2,5 na lista do dia) cai na frequência observada do
     * perfil, marcado como tal: é preço de coorte, não do jogo.
     *
     * @return list<array<string, mixed>>
     */
    public function getFilteredRowsProperty(): array
    {
        $source = $this->mode === 'history'
            ? array_slice(array_values(array_filter($this->settledHistory(), fn (array $row): bool => in_array($this->signalProfile, $row['profiles'], true))), 0, self::HISTORY_DISPLAY_LIMIT)
            : array_values(array_filter($this->rows, fn (array $row): bool => in_array($this->signalProfile, $row['profiles'] ?? [], true)));

        if ($this->mode === 'upcoming' && $this->hourFilter !== 'all') {
            $source = array_values(array_filter(
                $source,
                fn (array $row): bool => is_string($row['kickoffAt'] ?? null)
                    && BrasiliaDate::hourLabelFromKickoff((string) $row['kickoffAt']) === $this->hourFilter,
            ));
        }

        $cohortFrequency = $this->currentStats['frequency'];

        return array_map(function (array $row) use ($cohortFrequency): array {
            $ownProbability = $row['probability'];
            $probability = $ownProbability ?? $cohortFrequency;
            $fair = LayPricing::fairOdd($probability);
            $hash = self::quoteHash((string) $row['matchKey']);

            return [...$row,
                'quoteHash' => $hash,
                'priceProbability' => $probability,
                'priceIsCohort' => $ownProbability === null,
                'fairOdd' => $fair,
                'maxEntryOdd' => LayPricing::maxEntryOdd($fair),
                'verdict' => $this->mode === 'upcoming' ? $this->verdict($probability, $this->quoteInputs[$hash] ?? null) : null,
            ];
        }, $source);
    }

    /**
     * Veredito da odd digitada contra a odd máxima de entrada do jogo.
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
        $row = collect($this->filteredRows)->firstWhere('quoteHash', $hash);
        $offered = $this->parseOdd($this->quoteInputs[$hash] ?? null);

        if ($user === null || $row === null || $this->mode !== 'upcoming') {
            return;
        }
        if ($offered === null) {
            Notification::make()->title('Digite a odd de lay da exchange')->body('Um número maior que 1, por exemplo 18,5.')->warning()->send();

            return;
        }

        LayOddQuote::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'strategy' => $this->strategyKey(), 'match_key' => (string) $row['matchKey']],
            [
                'match_date' => substr((string) $row['matchDate'], 0, 10),
                'kickoff_at' => $row['kickoffAt'] ?? null,
                'home_team' => (string) $row['homeTeam'],
                'away_team' => (string) $row['awayTeam'],
                'competition' => $row['competition'] ?? null,
                'profile' => $this->signalProfile,
                'probability' => $row['priceProbability'],
                'fair_odd' => $row['fairOdd'],
                'offered_odd' => $offered,
            ],
        );

        $this->quoteInputs[$hash] = $offered;
        Notification::make()->title('Odd registrada')->body($row['homeTeam'].' x '.$row['awayTeam'].' · '.number_format($offered, 2, ',', '.'))->success()->send();
    }

    public function deleteQuote(int $id): void
    {
        LayOddQuote::query()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->where('strategy', $this->strategyKey())
            ->delete();
        $this->prefillQuoteInputs();
    }

    /**
     * Últimas cotações do usuário nesta estratégia, apuradas quando o jogo já está no
     * match_history.
     *
     * @return list<array<string, mixed>>
     */
    public function getQuotesProperty(): array
    {
        $quotes = LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->where('strategy', $this->strategyKey())
            ->orderByDesc('match_date')
            ->orderByDesc('id')
            ->get();

        $scores = $this->finalScoresFor($quotes);

        return $quotes->map(function (LayOddQuote $quote) use ($scores): array {
            $score = $scores[$quote->match_date->format('Y-m-d').'|'.$quote->home_team.'|'.$quote->away_team] ?? null;
            $result = $score === null ? null : $this->strategy()->result($score);

            return [
                'id' => $quote->id,
                'matchDate' => $quote->match_date->format('Y-m-d'),
                'homeTeam' => $quote->home_team,
                'awayTeam' => $quote->away_team,
                'competition' => $quote->competition,
                'offeredOdd' => $quote->offered_odd,
                'fairOdd' => $quote->fair_odd,
                'probability' => $quote->probability,
                'ratio' => $quote->ratio(),
                'score' => $score,
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
     * O resumo que responde se a exchange paga abaixo da justa.
     *
     * @return array{count: int, medianRatio: ?float, enterShare: ?float, expectedReturn: ?float, settled: int, reds: int, realizedReturn: ?float}
     */
    public function getQuoteSummaryProperty(): array
    {
        $quotes = $this->quotes;
        $ratios = array_values(array_filter(array_column($quotes, 'ratio'), fn ($r): bool => $r !== null));
        sort($ratios);
        $expected = array_values(array_filter(array_map(
            fn (array $q): ?float => $q['probability'] === null ? null : LayPricing::expectedReturn($q['probability'], $q['offeredOdd']),
            $quotes,
        ), fn ($r): bool => $r !== null));
        $settled = array_values(array_filter($quotes, fn (array $q): bool => $q['result'] !== null));
        $count = count($ratios);

        return [
            'count' => count($quotes),
            'medianRatio' => $count === 0 ? null : ($count % 2 ? $ratios[intdiv($count, 2)] : ($ratios[$count / 2 - 1] + $ratios[$count / 2]) / 2),
            'enterShare' => $count === 0 ? null : count(array_filter($ratios, fn (float $r): bool => $r <= LayPricing::ENTRY_RATIO)) / $count * 100,
            'expectedReturn' => $expected === [] ? null : array_sum($expected) / count($expected),
            'settled' => count($settled),
            'reds' => count(array_filter($settled, fn (array $q): bool => $q['result'] === 'red')),
            'realizedReturn' => $settled === [] ? null : array_sum(array_column($settled, 'realizedReturn')) / count($settled) * 100,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, LayOddQuote> $quotes
     * @return array<string, array{homeGoals: int, awayGoals: int}>
     */
    private function finalScoresFor($quotes): array
    {
        $pending = $quotes
            ->filter(fn (LayOddQuote $quote): bool => $quote->match_date->format('Y-m-d') <= BrasiliaDate::today())
            ->map(fn (LayOddQuote $quote): array => ['date' => $quote->match_date->format('Y-m-d'), 'home' => $quote->home_team, 'away' => $quote->away_team])
            ->values()
            ->all();

        try {
            return app(PunterMatchPickService::class)->finalScores($pending);
        } catch (\Throwable) {
            return [];
        }
    }

    private function prefillQuoteInputs(): void
    {
        $this->quoteInputs = [];
        if ($this->mode !== 'upcoming' || Auth::id() === null) {
            return;
        }

        $saved = LayOddQuote::query()
            ->where('user_id', Auth::id())
            ->where('strategy', $this->strategyKey())
            ->whereIn('match_key', array_column($this->rows, 'matchKey'))
            ->pluck('offered_odd', 'match_key');

        foreach ($saved as $matchKey => $odd) {
            $this->quoteInputs[self::quoteHash((string) $matchKey)] = (float) $odd;
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
        return HistoryCsv::download(
            $this->historyCsvFilename(),
            ['Data', 'Casa', 'Visitante', 'Liga', 'Favorito', 'Chance do placar', 'Odd justa', 'Placar final', 'Resultado'],
            array_map(fn (array $row): array => [
                $row['matchDate'] ?? '',
                $row['homeTeam'] ?? '',
                $row['awayTeam'] ?? '',
                $row['competition'] ?? '',
                $row['favourite'] === null ? '' : number_format($row['favourite'] * 100, 1, ',', ''),
                $row['priceProbability'] === null ? '' : number_format($row['priceProbability'], 2, ',', ''),
                $row['fairOdd'] === null ? '' : number_format($row['fairOdd'], 2, ',', ''),
                ($row['homeGoals'] ?? '—').'-'.($row['awayGoals'] ?? '—'),
                $row['result'] ?? '',
            ], $this->filteredRows),
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

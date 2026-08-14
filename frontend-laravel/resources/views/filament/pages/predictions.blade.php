@php
    $marketOptions = collect(\App\Oracly\Services\PredictionService::MARKETS)->map(fn ($m) => $m['label'])->all();
    $probabilityOptions = collect($this::CONFIDENCE_THRESHOLDS)->mapWithKeys(fn ($t) => [$t => "≥ {$t}%"])->all();
    $marketLabel = \App\Oracly\Services\PredictionService::MARKETS[$market]['label'] ?? $market;
@endphp

<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Banco local · previsão pré-jogo × placar · somente ligas principais">
        {{ $marketLabel }}.

        <x-slot name="description">
            {{ $mode === 'history' ? 'Histórico consolidado com a última previsão registrada antes do início da partida.' : 'Próximos jogos com previsão do modelo acima do corte selecionado.' }}
            @if ($market === 'btts')
                A entrada é validada quando os dois times marcam no placar final.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$marketOptions" :active="$market" method="setMarket" />
    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$probabilityOptions" :active="$minProbability" method="setMinProbability" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="prediction-score-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="prediction-score-filter" wire:model.live="scoreFilter" class="oracly-select">
                @foreach ($this->scoreOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <x-oracly.stat-tile
            hero
            accent
            :value="$this->stats['hitRate'] !== null ? number_format($this->stats['hitRate'], 0).'%' : number_format($this->stats['coverage'], 0).'%'"
            :label="'Corte ≥ '.$minProbability.'%'"
        >
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                @if ($this->stats['hitRate'] !== null)
                    {{ $this->stats['wins'] }} acertos em {{ $this->stats['entries'] }} previsões selecionadas.
                @else
                    {{ $this->stats['entries'] }} jogos futuros selecionados.
                @endif
            </p>
        </x-oracly.stat-tile>
        <div class="grid grid-cols-2 gap-3">
            <x-oracly.stat-tile :value="$this->stats['sampleSize']" label="Amostra elegível" />
            <x-oracly.stat-tile :value="$this->stats['entries']" label="Entradas" />
            @if ($this->stats['wins'] !== null)
                <x-oracly.stat-tile :value="$this->stats['wins']" label="Vitórias" />
            @endif
            <x-oracly.stat-tile :value="number_format($this->stats['coverage'], 0).'%'" label="Cobertura" />
        </div>
    </div>

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Linha de confiança</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ($this->confidenceLine as $threshold => $data)
                <x-oracly.stat-tile
                    :active="$minProbability === $threshold"
                    :value="$data['hitRate'] !== null ? number_format($data['hitRate'], 0).'%' : number_format($data['coverage'], 0).'%'"
                    :label="'Probabilidade ≥ '.$threshold.'%'"
                />
            @endforeach
        </div>
    </div>

    @if ($mode === 'history' && count($this->marketSummary))
        <div>
            <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Acerto por mercado neste corte</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach ($this->marketSummary as $data)
                    <x-oracly.stat-tile
                        :active="$data['active']"
                        :value="$data['hitRate'] !== null ? number_format($data['hitRate'], 0).'%' : '—'"
                        :label="$data['label']"
                    />
                @endforeach
            </div>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>Horário</th>
                    <th>Jogo</th>
                    <th>Prob.</th>
                    @if ($market === 'btts')
                        <th>BTTS base</th>
                    @endif
                    @if ($mode === 'history')
                        <th>Resultado</th>
                        <th>Acerto</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('d/m H:i') : '—' }}</td>
                        <td>
                            <div class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</div>
                            @php $leagueKey = ($row['country'] ?? '').'::'.($row['competition'] ?? ''); @endphp
                            <button type="button" wire:click="toggleLeague({{ json_encode($row['country']) }}, {{ json_encode($row['competition']) }})" wire:loading.attr="disabled" wire:target="toggleLeague" class="flex items-center gap-2 text-left text-xs text-gray-500 hover:text-amber-500 dark:text-gray-400" title="{{ in_array($leagueKey, $favoriteLeagues, true) ? 'Remover liga dos favoritos' : 'Adicionar liga aos favoritos' }}">
                                {{ $row['country'] }} · {{ $row['competition'] }}
                                <span class="text-base leading-none {{ in_array($leagueKey, $favoriteLeagues, true) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500' }}" aria-hidden="true">{{ in_array($leagueKey, $favoriteLeagues, true) ? '★' : '☆' }}</span>
                            </button>
                        </td>
                        <td class="font-semibold">{{ number_format($row['probability'], 0) }}%</td>
                        @if ($market === 'btts')
                            <td>{{ ($row['bttsPercentage'] ?? null) !== null ? number_format($row['bttsPercentage'], 0).'%' : '—' }}</td>
                        @endif
                        @if ($mode === 'history')
                            <td>
                                @if ($market === 'btts' && ($row['homeScore'] ?? null) !== null && ($row['awayScore'] ?? null) !== null)
                                    FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}
                                @else
                                    HT {{ $row['halftimeGoals'] }} · FT {{ $row['finalGoals'] }}
                                @endif
                            </td>
                            <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-gray-500 dark:text-gray-400">Sem resultados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

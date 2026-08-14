<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Estratégia pré-jogo · dados próprios">
        Jogos para Ambas Marcam.

        <x-slot name="description">
            @if ($mode === 'history')
                Histórico da última previsão registrada antes do início da partida.
            @else
                {{ count($this->filteredRows) }} jogos com previsão BTTS de pelo menos {{ $minProbability }}%.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group
        :options="$this::PROBABILITY_OPTIONS"
        :active="$minProbability"
        method="setMinProbability"
    />

    <x-oracly.chip-group
        :options="$this::FAVORITE_OPTIONS"
        :active="$favoriteFilter"
        method="setFavoriteFilter"
    />

    @if ($mode === 'history')
        <div>
            <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade por corte</h2>
            <div class="overflow-x-auto">
                <table class="oracly-table min-w-[42rem] table-fixed">
                    <thead><tr>
                        @foreach ($this->cutoffStats as $threshold => $stat)
                            <th class="text-center">BTTS ≥ {{ $threshold }}%</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        <tr>
                            @foreach ($this->cutoffStats as $threshold => $stat)
                                <td class="text-center font-semibold {{ $minProbability === $threshold ? 'text-amber-600 dark:text-amber-300' : '' }}">{{ $stat['hitRate'] !== null ? number_format($stat['hitRate'], 1).'%' : '—' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($this->cutoffStats as $stat)
                                <td class="text-center text-xs text-gray-500 dark:text-gray-400">{{ $stat['wins'] }}G · {{ $stat['reds'] }}R / {{ $stat['entries'] }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="max-w-xs">
            <label for="btts-score" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="btts-score" wire:model.live="scoreFilter" class="oracly-select">
                @foreach ($this->scoreOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                    <th>Jogo</th>
                    <th>Liga</th>
                    <th>BTTS</th>
                    @if ($mode === 'history')
                        <th>HT</th>
                        <th>FT</th>
                        <th>Resultado</th>
                    @else
                        <th>Placar</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format($mode === 'history' ? 'd/m H:i' : 'H:i') : '—' }}</td>
                        <td class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">
                            @if ($mode === 'upcoming' && $row['country'] && $row['competition'])
                                @php $leagueKey = $row['country'].'::'.$row['competition']; @endphp
                                <button
                                    type="button"
                                    wire:click="toggleLeague({{ json_encode($row['country']) }}, {{ json_encode($row['competition']) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleLeague"
                                    class="flex items-center gap-2 text-left hover:text-amber-500"
                                    title="{{ in_array($leagueKey, $favoriteLeagues, true) ? 'Remover liga dos favoritos' : 'Adicionar liga aos favoritos' }}"
                                >
                                    {{ $row['country'] }} · {{ $row['competition'] }}
                                    <span class="text-base leading-none {{ in_array($leagueKey, $favoriteLeagues, true) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500' }}" aria-hidden="true">{{ in_array($leagueKey, $favoriteLeagues, true) ? '★' : '☆' }}</span>
                                </button>
                            @else
                                {{ $row['country'] }} · {{ $row['competition'] }}
                            @endif
                        </td>
                        <td class="font-semibold">
                            @php $probability = $mode === 'history' ? $row['probability'] : $row['btts']; @endphp
                            @if ($probability >= 80)
                                <span class="oracly-cell-highlight">{{ number_format($probability, 0) }}%</span>
                            @else
                                {{ number_format($probability, 0) }}%
                            @endif
                        </td>
                        @if ($mode === 'history')
                            <td>{{ $row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null ? $row['halftimeHomeScore'].'-'.$row['halftimeAwayScore'] : '—' }}</td>
                            <td>{{ $row['homeScore'] }}-{{ $row['awayScore'] }}</td>
                            <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                        @else
                            <td class="whitespace-nowrap">
                                @if ($row['homeScore'] !== null && $row['awayScore'] !== null)
                                    FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}
                                @else
                                    {{ $row['status'] ?? '—' }}
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $mode === 'history' ? 7 : 5 }}" class="py-6 text-gray-500 dark:text-gray-400">Nenhum jogo BTTS neste corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

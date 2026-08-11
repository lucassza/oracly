<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Hoje · ordenado por horário · somente ligas principais">
        Jogos de hoje.

        <x-slot name="description">
            {{ count($this->filteredRows) }} de {{ count($rows) }} jogos hoje.
            Chip destacado = previsão do modelo ≥ {{ $this::HIGHLIGHT_PROBABILITY }}%.
        </x-slot>
    </x-oracly.page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-oracly.stat-tile
            :value="$this->marketCounts['all']"
            label="Todos os mercados"
            :active="$minProbability === 0"
        />
        @foreach ($this::MARKET_TILES as $market => $label)
            <x-oracly.stat-tile
                :value="$this->marketCounts[$market]"
                :label="$label"
            />
        @endforeach
    </div>

    <x-oracly.chip-group
        :options="$this::PROBABILITY_OPTIONS"
        :active="$minProbability"
        method="setMinProbability"
    />

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>Horário</th>
                    <th>Jogo</th>
                    <th>Placar</th>
                    @foreach ($this::MARKET_TILES as $label)
                        <th>{{ $label }}</th>
                    @endforeach
                    <th>Sinal</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') : '—' }}</td>
                        <td>
                            <div class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</div>
                            @if ($row['country'] && $row['competition'])
                                @php $leagueKey = $row['country'].'::'.$row['competition']; @endphp
                                <button
                                    type="button"
                                    wire:click="toggleLeague({{ json_encode($row['country']) }}, {{ json_encode($row['competition']) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleLeague"
                                    class="flex items-center gap-2 text-left text-xs text-gray-500 hover:text-amber-500 dark:text-gray-400"
                                    title="{{ in_array($leagueKey, $favoriteLeagues, true) ? 'Remover liga dos favoritos' : 'Adicionar liga aos favoritos' }}"
                                >
                                    <span wire:loading.remove wire:target="toggleLeague">
                                        {{ $row['country'] }} · {{ $row['competition'] }}
                                        <span class="text-base leading-none {{ in_array($leagueKey, $favoriteLeagues, true) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500' }}" aria-hidden="true">{{ in_array($leagueKey, $favoriteLeagues, true) ? '★' : '☆' }}</span>
                                    </span>
                                    <span wire:loading wire:target="toggleLeague" class="text-gray-400">Salvando…</span>
                                </button>
                            @else
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['country'] }} · {{ $row['competition'] }}</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($row['status'] === 'finished' && $row['homeScore'] !== null && $row['awayScore'] !== null)
                                @if ($row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">HT {{ $row['halftimeHomeScore'] }}-{{ $row['halftimeAwayScore'] }}</div>
                                @endif
                                <div>FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}</div>
                            @elseif ($row['homeScore'] !== null && $row['awayScore'] !== null)
                                {{ $row['homeScore'] }}–{{ $row['awayScore'] }}
                                @if (in_array($row['status'], $this::OPEN_STATUSES, true))
                                    <span class="oracly-badge oracly-badge--win">{{ $row['liveMinute'] ? $row['liveMinute']."'" : 'ao vivo' }}</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        @foreach (array_keys($this::MARKET_TILES) as $market)
                            @php $probability = $row['predictions'][$market]['probability'] ?? null; @endphp
                            <td>
                                @if ($probability === null)
                                    <span class="text-gray-400 dark:text-gray-600">—</span>
                                @elseif ($probability >= $this::HIGHLIGHT_PROBABILITY)
                                    <span class="oracly-cell-highlight">{{ number_format($probability, 0) }}%</span>
                                @else
                                    {{ number_format($probability, 0) }}%
                                @endif
                            </td>
                        @endforeach
                        <td>{{ $row['signalScore'] }}/4</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-6 text-gray-500 dark:text-gray-400">Nenhum jogo nesta data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Lista diária · Ambas marcam · somente ligas principais">
        Jogos para Ambas Marcam.

        <x-slot name="description">
            {{ count($this->filteredRows) }} jogos com previsão BTTS de pelo menos {{ $minProbability }}%.
        </x-slot>
    </x-oracly.page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-oracly.stat-tile :value="count($this->filteredRows)" label="Entradas BTTS" active />
        <x-oracly.stat-tile :value="$minProbability.'%'" label="Corte mínimo" />
        <x-oracly.stat-tile :value="$date" label="Data" />
    </div>

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

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>Horário</th>
                    <th>Jogo</th>
                    <th>Liga</th>
                    <th>BTTS</th>
                    <th>Placar</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') : '—' }}</td>
                        <td class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">
                            @if ($row['country'] && $row['competition'])
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
                            @if ($row['btts'] >= 80)
                                <span class="oracly-cell-highlight">{{ number_format($row['btts'], 0) }}%</span>
                            @else
                                {{ number_format($row['btts'], 0) }}%
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($row['homeScore'] !== null && $row['awayScore'] !== null)
                                FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}
                            @else
                                {{ $row['status'] ?? '—' }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-gray-500 dark:text-gray-400">Nenhum jogo BTTS neste corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

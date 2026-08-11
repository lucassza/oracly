<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Lista diária · somente ligas principais">
        Lista de jogos.

        <x-slot name="description">
            {{ count($this->filteredRows) }} de {{ count($rows) }} jogos exibidos.
        </x-slot>
    </x-oracly.page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-oracly.stat-tile :value="count($this->filteredRows)" label="Jogos filtrados" active />
        <x-oracly.stat-tile :value="$pageNumber" :label="'Página de '.$this->totalPages" />
        <x-oracly.stat-tile :value="count($this->pagedRows)" label="Jogos nesta página" />
    </div>

    <x-oracly.chip-group
        :options="$this::FAVORITE_OPTIONS"
        :active="$favoriteFilter"
        method="setFavoriteFilter"
    />

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
                    <th>Liga</th>
                    <th>Placar</th>
                    <th>O0.5</th>
                    <th>O1.5</th>
                    <th>O2.5</th>
                    <th>U3.5</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->pagedRows as $row)
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
                                    <span wire:loading.remove wire:target="toggleLeague">
                                        {{ $row['country'] }} · {{ $row['competition'] }}
                                        <span class="text-base leading-none {{ in_array($leagueKey, $favoriteLeagues, true) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500' }}" aria-hidden="true">{{ in_array($leagueKey, $favoriteLeagues, true) ? '★' : '☆' }}</span>
                                    </span>
                                    <span wire:loading wire:target="toggleLeague" class="text-gray-400">Salvando…</span>
                                </button>
                            @else
                                {{ $row['country'] }} · {{ $row['competition'] }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($row['homeScore'] !== null)
                                @if ($row['halftimeHomeScore'] !== null)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">HT {{ $row['halftimeHomeScore'] }}-{{ $row['halftimeAwayScore'] }}</div>
                                @endif
                                <div>FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}</div>
                            @else
                                {{ $row['status'] ?? '—' }}
                            @endif
                        </td>
                        @foreach (['over05', 'over15', 'over25', 'under35'] as $market)
                            @php $probability = $row[$market] ?? null; @endphp
                            <td>
                                @if ($probability === null)
                                    <span class="text-gray-400 dark:text-gray-600">—</span>
                                @elseif ($probability >= 80)
                                    <span class="oracly-cell-highlight">{{ number_format($probability, 0) }}%</span>
                                @else
                                    {{ number_format($probability, 0) }}%
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-6 text-gray-500 dark:text-gray-400">Nenhum pick para esta data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button
            type="button"
            wire:click="$set('pageNumber', {{ max(1, $pageNumber - 1) }})"
            @disabled($pageNumber <= 1)
            class="oracly-chip"
        >
            Anterior
        </button>
        <span class="text-sm text-gray-500 dark:text-gray-400">Página {{ $pageNumber }} / {{ $this->totalPages }}</span>
        <button
            type="button"
            wire:click="$set('pageNumber', {{ min($this->totalPages, $pageNumber + 1) }})"
            @disabled($pageNumber >= $this->totalPages)
            class="oracly-chip"
        >
            Próxima
        </button>
    </div>
</x-filament-panels::page>

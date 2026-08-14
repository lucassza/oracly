<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Placar final · somente ligas principais">
        Excluir dois placares FT.

        <x-slot name="description">
            Entre 0x1, 0x2, 1x0, 2x0, 2x1 e 1x2, a estratégia exclui os dois menos prováveis pelas médias de gols pré-jogo.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />
    @if ($mode === 'upcoming')<x-oracly.chip-group :options="$this::BEST_PER_HOUR_OPTIONS" :active="$bestPerHourFilter" method="setBestPerHourFilter" />@endif

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-oracly.stat-tile :value="$this->stats['entries']" :label="$mode === 'history' ? 'Jogos analisados' : 'Próximos jogos'" />
        @if ($this->stats['hitRate'] !== null)
            <x-oracly.stat-tile hero accent :value="number_format($this->stats['hitRate'], 0).'%'" label="Os dois placares não ocorreram" />
            <x-oracly.stat-tile :value="$this->stats['wins']" label="Acertos" />
        @endif
    </div>

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="final-score-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="final-score-filter" wire:model.live="scoreFilter" class="oracly-select">
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
                    <th>Placares excluídos</th>
                    <th>Chance conjunta</th>
                    @if ($mode === 'history')
                        <th>Placar FT</th>
                        <th>Acerto</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format($mode === 'history' ? 'd/m H:i' : 'H:i') : '—' }}</td>
                        <td class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">
                            @php $leagueKey = ($row['country'] ?? '').'::'.($row['competition'] ?? ''); @endphp
                            <button type="button" wire:click="toggleLeague({{ json_encode($row['country']) }}, {{ json_encode($row['competition']) }})" wire:loading.attr="disabled" wire:target="toggleLeague" class="flex items-center gap-2 text-left hover:text-amber-500" title="{{ in_array($leagueKey, $favoriteLeagues, true) ? 'Remover liga dos favoritos' : 'Adicionar liga aos favoritos' }}">
                                {{ $row['country'] }} · {{ $row['competition'] }}
                                <span class="text-base leading-none {{ in_array($leagueKey, $favoriteLeagues, true) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500' }}" aria-hidden="true">{{ in_array($leagueKey, $favoriteLeagues, true) ? '★' : '☆' }}</span>
                            </button>
                        </td>
                        <td class="font-semibold">{{ implode(' e ', $row['excluded']) }}</td>
                        <td>{{ number_format($row['combinedProbability'] * 100, 1) }}% @if ($mode === 'upcoming')<x-oracly.opportunity-rank-badge :rank="$row['opportunityRank'] ?? null" />@endif</td>
                        @if ($mode === 'history')
                            <td>{{ $row['actual'] ?? '—' }}</td>
                            <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $mode === 'history' ? 7 : 5 }}" class="py-6 text-gray-500 dark:text-gray-400">Sem jogos com médias de gols suficientes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

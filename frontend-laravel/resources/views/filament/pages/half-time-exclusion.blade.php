<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Banco local · exclusão de resultado no 1º tempo · somente ligas principais">
        Exclusão 1º tempo.

        <x-slot name="description">
            {{ $mode === 'history' ? 'Histórico de acordo entre fontes × resultado real do intervalo.' : 'Próximos jogos com sinal de exclusão calculado.' }}
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />
    <div class="oracly-chip-group">
        <button
            type="button"
            wire:click="setAgreementFilter('')"
            wire:loading.attr="disabled"
            wire:target="setAgreementFilter"
            class="oracly-chip {{ $agreementFilters === [] ? 'oracly-chip--active' : '' }}"
        >Todos os acordos</button>
        @foreach ($this::AGREEMENT_TIERS as $tier)
            <button
                type="button"
                wire:click="setAgreementFilter({{ json_encode($tier) }})"
                wire:loading.attr="disabled"
                wire:target="setAgreementFilter"
                class="oracly-chip {{ in_array($tier, $agreementFilters, true) ? 'oracly-chip--active' : '' }}"
            >{{ $tier }}</button>
        @endforeach
    </div>

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="ht-score-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="ht-score-filter" wire:model.live="scoreFilter" class="oracly-select">
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
            :label="'Acordo '.($agreementFilters !== [] ? implode(', ', $agreementFilters) : 'todos')"
        >
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                @if ($this->stats['hitRate'] !== null)
                    {{ $this->stats['wins'] }} acertos de exclusão em {{ $this->stats['entries'] }} jogos.
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
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Linha de confiança por acordo entre fontes</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($this->confidenceLine as $tier => $data)
                <x-oracly.stat-tile
                    :active="in_array($tier, $agreementFilters, true)"
                    :value="$data['hitRate'] !== null ? number_format($data['hitRate'], 0).'%' : number_format($data['coverage'], 0).'%'"
                    :label="'Acordo '.$tier"
                />
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>Horário</th>
                    <th>Jogo</th>
                    <th>HT</th>
                    <th>FT</th>
                    <th>Excluir</th>
                    <th>Acordo</th>
                    @if ($mode === 'history')
                        <th>Real</th>
                        <th>Acerto</th>
                    @endif
                    <th>Fav</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('d/m H:i') : '—' }}</td>
                        <td>
                            <div class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['country'] }} · {{ $row['competition'] }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            @if (($row['halftimeHomeScore'] ?? null) !== null && ($row['halftimeAwayScore'] ?? null) !== null)
                                {{ $row['halftimeHomeScore'] }}-{{ $row['halftimeAwayScore'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if (($row['homeScore'] ?? null) !== null && ($row['awayScore'] ?? null) !== null)
                                {{ $row['homeScore'] }}-{{ $row['awayScore'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="font-semibold uppercase">{{ $row['excluded'] }}</td>
                        <td>{{ $row['agreementKey'] }}</td>
                        @if ($mode === 'history')
                            <td class="uppercase">{{ $row['actual'] }}</td>
                            <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                        @endif
                        <td>
                            @if (! empty($row['country']) && ! empty($row['competition']))
                                @php $leagueKey = $row['country'].'::'.$row['competition']; @endphp
                                <button
                                    type="button"
                                    class="text-amber-500 hover:text-amber-400"
                                    wire:click="toggleFavoriteLeague({{ json_encode($row['country']) }}, {{ json_encode($row['competition']) }})"
                                    title="{{ in_array($leagueKey, $favoriteLeagues, true) ? 'Remover liga dos favoritos' : 'Adicionar liga aos favoritos' }}"
                                >
                                    {{ in_array($leagueKey, $favoriteLeagues, true) ? '★' : '☆' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-6 text-gray-500 dark:text-gray-400">Sem resultados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

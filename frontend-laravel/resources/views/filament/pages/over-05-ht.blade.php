<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Estratégia pré-jogo · dados próprios">
        Over 0.5 HT.

        <x-slot name="description">
            Gol no primeiro tempo, com a última previsão registrada antes do início da partida. Use o histórico para validar o corte antes de qualquer entrada.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::THRESHOLDS" :active="$minProbability" method="setMinProbability" />
    <x-oracly.chip-group :options="$this::SIGNAL_PROFILES" :active="$signalProfile" method="setSignalProfile" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />
    @if ($mode === 'history')<x-oracly.chip-group :options="$this::BACKFILL_OPTIONS" :active="$backfillFilter" method="setBackfillFilter" />@endif

    <p class="text-xs text-gray-500 dark:text-gray-400">
        @if ($signalProfile === 'balanced')
            Ataque confirmado: X7 ≥ 70%, média de gols do 1º tempo ≥ 1,4 e O1.5 HT ≥ 45%. Medido em 80,4% de acerto.
        @elseif ($signalProfile === 'strong')
            Sinal forte: X7 ≥ 75%, média de gols do 1º tempo ≥ 1,6 e O1.5 HT ≥ 50%. Medido em 85,2% de acerto, com bem menos entradas.
        @elseif ($signalProfile === 'legacy80')
            Legado: apenas X7 ≥ 80%, a regra que roda hoje no card diário. Serve para comparar com os perfis acima.
        @else
            Base: apenas X7 ≥ 70%, sem filtro próprio. Medido em 75,8% de acerto.
        @endif
        @if ($mode === 'history' && $backfillFilter === 1)
            <span class="text-amber-600 dark:text-amber-400">Incluindo partidas cujas médias de 1º tempo foram buscadas depois do jogo — podem não refletir a forma do time antes da partida.</span>
        @endif
    </p>

    @if ($mode === 'upcoming')<x-oracly.chip-group :options="$this::BEST_PER_HOUR_OPTIONS" :active="$bestPerHourFilter" method="setBestPerHourFilter" />@endif

    @if ($mode === 'history' && $this->bestCutoff)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Melhor corte {{ $favoriteFilter === 1 ? 'nas favoritas' : 'na base atual' }}:
            <span class="font-semibold text-emerald-700 dark:text-emerald-300">O0.5 HT ≥ {{ $this->bestCutoff['threshold'] }}% · {{ number_format($this->bestCutoff['hitRate'], 0) }}%</span>
            <span class="text-gray-500 dark:text-gray-400">({{ $this->bestCutoff['wins'] }} greens · {{ $this->bestCutoff['reds'] }} reds / {{ $this->bestCutoff['entries'] }} jogos)</span>
        </p>
    @endif

    @if ($mode === 'history')
        <div>
            <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade por corte</h2>
            <div class="overflow-x-auto">
                <table class="oracly-table min-w-[42rem] table-fixed">
                <thead><tr>
                    @foreach ($this->cutoffStats as $threshold => $stat)
                        <th class="text-center">O0.5 HT ≥ {{ $threshold }}%</th>
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
    @endif

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="over-05-ht-score" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="over-05-ht-score" wire:model.live="scoreFilter" class="oracly-select">
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
                    <th>O0.5 HT</th>
                    @if ($mode === 'history')
                        <th>HT</th>
                        <th>FT</th>
                        <th>Resultado</th>
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
                        <td class="font-semibold">{{ number_format($row['probability'], 0) }}% @if ($mode === 'upcoming')<x-oracly.opportunity-rank-badge :rank="$row['opportunityRank'] ?? null" />@endif</td>
                        @if ($mode === 'history')
                            <td>{{ $row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null ? $row['halftimeHomeScore'].'-'.$row['halftimeAwayScore'] : '—' }}</td>
                            <td>{{ $row['homeScore'] }}-{{ $row['awayScore'] }}</td>
                            <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $mode === 'history' ? 7 : 4 }}" class="py-6 text-gray-500 dark:text-gray-400">Sem jogos neste corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

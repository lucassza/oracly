<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Estratégia pré-jogo · dados próprios">
        Over 0.5 HT.

        <x-slot name="description">
            Gol no primeiro tempo, com a última previsão registrada antes do início da partida. Use o histórico para validar o corte antes de qualquer entrada.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::THRESHOLDS" :active="$minProbability" method="setMinProbability" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />

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
                        <td class="text-gray-500 dark:text-gray-400">{{ $row['country'] }} · {{ $row['competition'] }}</td>
                        <td class="font-semibold">{{ number_format($row['probability'], 0) }}%</td>
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

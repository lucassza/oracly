<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Estratégia FT + HT · somente ligas principais">
        Escolha única: contra 0x1 ou 1x0.

        <x-slot name="description">
            Para cada jogo, escolhemos o menos provável entre 0x1 e 1x0 pelas médias de gols pré-jogo. Os cards medem separadamente o resultado no FT e no HT.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::THRESHOLDS" :active="$minProbability" method="setMinProbability" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />

    @if ($this->bestCutoff)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Melhor corte global: <span class="font-semibold text-emerald-700 dark:text-emerald-300">O1.5 ≥ {{ $this->bestCutoff['threshold'] }}% · {{ number_format($this->bestCutoff['hitRate'], 0) }}%</span>
            <span class="text-gray-500 dark:text-gray-400">({{ $this->bestCutoff['wins'] }} greens · {{ $this->bestCutoff['reds'] }} reds / {{ $this->bestCutoff['entries'] }} jogos)</span>
        </p>
    @endif

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade por corte O1.5</h2>
        <div class="max-w-7xl space-y-4">
            @foreach (['1-0' => 'Contra 1x0', '0-1' => 'Contra 0x1'] as $method => $methodLabel)
                <div>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $methodLabel }}</h3>
                    <div class="overflow-x-auto">
                        <table class="oracly-table min-w-[42rem] table-fixed">
                            <thead><tr>
                                @foreach ($this->cutoffStatsByMethod[$method] as $threshold => $stat)
                                    <th class="text-center">O1.5 ≥ {{ $threshold }}%</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                <tr>
                                    @foreach ($this->cutoffStatsByMethod[$method] as $threshold => $stat)
                                        <td class="text-center font-semibold {{ $minProbability === $threshold ? 'text-amber-600 dark:text-amber-300' : '' }}">{{ $stat['hitRate'] !== null ? number_format($stat['hitRate'], 0).'%' : '—' }}</td>
                                    @endforeach
                                </tr>
                                <tr>
                                    @foreach ($this->cutoffStatsByMethod[$method] as $stat)
                                        <td class="text-center text-xs text-gray-500 dark:text-gray-400">{{ $stat['wins'] }}G · {{ $stat['reds'] }}R / {{ $stat['entries'] }}</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade no HT por corte O1.5</h2>
        <div class="max-w-7xl space-y-4">
            @foreach (['1-0' => 'Contra 1x0', '0-1' => 'Contra 0x1'] as $method => $methodLabel)
                <div>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $methodLabel }}</h3>
                    <div class="overflow-x-auto">
                        <table class="oracly-table min-w-[42rem] table-fixed">
                            <thead><tr>
                                @foreach ($this->cutoffStatsByMethod[$method] as $threshold => $stat)
                                    <th class="text-center">O1.5 ≥ {{ $threshold }}%</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                <tr>
                                    @foreach ($this->cutoffStatsByMethod[$method] as $threshold => $stat)
                                        <td class="text-center font-semibold {{ $minProbability === $threshold ? 'text-amber-600 dark:text-amber-300' : '' }}">{{ $stat['htHitRate'] !== null ? number_format($stat['htHitRate'], 0).'%' : '—' }}</td>
                                    @endforeach
                                </tr>
                                <tr>
                                    @foreach ($this->cutoffStatsByMethod[$method] as $stat)
                                        <td class="text-center text-xs text-gray-500 dark:text-gray-400">{{ $stat['htWins'] }}G · {{ $stat['htReds'] }}R / {{ $stat['htEntries'] }}</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Resultados no HT por placar · corte selecionado</h2>
        <div class="max-w-5xl overflow-x-auto">
            <table class="oracly-table min-w-[42rem] table-fixed">
                <thead><tr>
                    @foreach (['1-0' => 'Contra 1x0', '0-1' => 'Contra 0x1'] as $method => $methodLabel)
                        @foreach (['1-0' => 'HT 1x0', '0-1' => 'HT 0x1', '0-0' => 'HT 0x0'] as $score => $label)
                            <th class="text-center">{{ $methodLabel }} · {{ $label }}</th>
                        @endforeach
                    @endforeach
                </tr></thead>
                <tbody><tr>
                    @foreach (['1-0', '0-1'] as $method)
                        @foreach (['1-0', '0-1', '0-0'] as $score)
                            <td class="text-center font-semibold">{{ $this->htResultsByMethod[$method][$score] }}</td>
                        @endforeach
                    @endforeach
                </tr></tbody>
            </table>
        </div>
    </div>

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="against-one-goal-score" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="against-one-goal-score" wire:model.live="scoreFilter" class="oracly-select">
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
                    <th>Odds 1X2</th>
                    <th>O1.5</th>
                    <th>O2.5</th>
                    <th>BTTS</th>
                    <th>Média gols</th>
                    <th>Melhor escolha</th>
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
                        <td class="whitespace-nowrap text-xs" title="{{ $row['oddsBookmaker'] ?? '' }}">
                            @if ($row['homeOdd'] !== null || $row['drawOdd'] !== null || $row['awayOdd'] !== null)
                                {{ $row['homeOdd'] !== null ? number_format($row['homeOdd'], 2) : '—' }} /
                                {{ $row['drawOdd'] !== null ? number_format($row['drawOdd'], 2) : '—' }} /
                                {{ $row['awayOdd'] !== null ? number_format($row['awayOdd'], 2) : '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="font-semibold">{{ number_format($row['probability'], 0) }}%</td>
                        <td>{{ ($row['over25Probability'] ?? null) !== null ? number_format($row['over25Probability'], 0).'%' : '—' }}</td>
                        <td>{{ ($row['bttsProbability'] ?? null) !== null ? number_format($row['bttsProbability'], 0).'%' : '—' }}</td>
                        <td>{{ ($row['combinedGoalsAverage'] ?? null) !== null ? number_format($row['combinedGoalsAverage'], 1) : '—' }}</td>
                        <td class="font-semibold">
                            @if ($row['bestAgainstScore'] ?? null)
                                Contra {{ str_replace('-', ' x ', $row['bestAgainstScore']) }}
                                <span class="font-normal text-xs text-gray-500">({{ number_format(($row['bestAgainstProbability'] ?? 0) * 100, 1) }}%)</span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($mode === 'history')
                            <td>{{ $row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null ? $row['halftimeHomeScore'].'-'.$row['halftimeAwayScore'] : '—' }}</td>
                            <td>{{ $row['homeScore'] }}-{{ $row['awayScore'] }}</td>
                            <td>
                                @if (($row['againstHit'] ?? null) === null && ($row['againstHtHit'] ?? null) === null)
                                    —
                                @else
                                    @if (($row['againstHit'] ?? null) !== null)
                                        FT <x-oracly.result-badge :hit="$row['againstHit']" />
                                    @endif
                                    @if (($row['againstHtHit'] ?? null) !== null)
                                        <br>
                                        HT <x-oracly.result-badge :hit="$row['againstHtHit']" />
                                    @endif
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $mode === 'history' ? 12 : 9 }}" class="py-6 text-gray-500 dark:text-gray-400">Sem jogos para este corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

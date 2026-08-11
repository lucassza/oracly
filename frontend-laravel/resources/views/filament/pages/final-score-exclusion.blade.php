<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Placar final · somente ligas principais">
        Excluir dois placares FT.

        <x-slot name="description">
            Entre 0x1, 0x2, 1x0, 2x0, 2x1 e 1x2, a estratégia exclui os dois menos prováveis pelas médias de gols pré-jogo.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-oracly.stat-tile :value="$this->stats['entries']" :label="$mode === 'history' ? 'Jogos analisados' : 'Próximos jogos'" />
        @if ($this->stats['hitRate'] !== null)
            <x-oracly.stat-tile hero accent :value="number_format($this->stats['hitRate'], 0).'%'" label="Os dois placares não ocorreram" />
            <x-oracly.stat-tile :value="$this->stats['wins']" label="Acertos" />
        @endif
    </div>

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
                @forelse ($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format($mode === 'history' ? 'd/m H:i' : 'H:i') : '—' }}</td>
                        <td class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $row['country'] }} · {{ $row['competition'] }}</td>
                        <td class="font-semibold">{{ implode(' e ', $row['excluded']) }}</td>
                        <td>{{ number_format($row['combinedProbability'] * 100, 1) }}%</td>
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

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $date }} — {{ count($rows) }} jogos</x-slot>
        <x-slot name="description">Leitura direta do Postgres Oracly.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">Horário</th>
                        <th class="py-2 pr-3">Jogo</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2 pr-3">Placar</th>
                        <th class="py-2 pr-3">O0.5 HT</th>
                        <th class="py-2 pr-3">O0.5 FT</th>
                        <th class="py-2 pr-3">O1.5 FT</th>
                        <th class="py-2 pr-3">BTTS%</th>
                        <th class="py-2 pr-3">Sinal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') : '—' }}</td>
                            <td class="py-2 pr-3">
                                <div>{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</div>
                                <div class="text-xs text-gray-500">{{ $row['country'] }} · {{ $row['competition'] }}</div>
                            </td>
                            <td class="py-2 pr-3">{{ $row['status'] }}{{ $row['liveMinute'] ? ' '.$row['liveMinute']."'" : '' }}</td>
                            <td class="py-2 pr-3">
                                @if ($row['homeScore'] !== null)
                                    {{ $row['homeScore'] }}-{{ $row['awayScore'] }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $row['predictions']['gols_1t_05_over']['probability'] ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['predictions']['over_05_ft_over']['probability'] ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['predictions']['over_15_ft_over']['probability'] ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['bttsPercentage'] ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['signalScore'] }}/4</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-6 text-gray-500">Nenhum jogo nesta data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

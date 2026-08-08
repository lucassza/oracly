<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $date }} — {{ count($rows) }} jogos</x-slot>
        <x-slot name="description">Dados lidos direto do Postgres Oracly (sem API Node).</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">Horário</th>
                        <th class="py-2 pr-3">Jogo</th>
                        <th class="py-2 pr-3">Liga</th>
                        <th class="py-2 pr-3">Placar</th>
                        <th class="py-2 pr-3">O0.5</th>
                        <th class="py-2 pr-3">O1.5</th>
                        <th class="py-2 pr-3">O2.5</th>
                        <th class="py-2 pr-3">U3.5</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->pagedRows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') : '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                            <td class="py-2 pr-3">{{ $row['country'] }} · {{ $row['competition'] }}</td>
                            <td class="py-2 pr-3">
                                @if ($row['homeScore'] !== null)
                                    {{ $row['homeScore'] }}-{{ $row['awayScore'] }}
                                @else
                                    {{ $row['status'] ?? '—' }}
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $row['over05'] !== null ? number_format($row['over05'], 0) : '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['over15'] !== null ? number_format($row['over15'], 0) : '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['over25'] !== null ? number_format($row['over25'], 0) : '—' }}</td>
                            <td class="py-2 pr-3">{{ $row['under35'] !== null ? number_format($row['under35'], 0) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-6 text-gray-500">Nenhum pick para esta data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <x-filament::button color="gray" wire:click="$set('pageNumber', {{ max(1, $pageNumber - 1) }})" :disabled="$pageNumber <= 1">Anterior</x-filament::button>
            <span class="text-sm text-gray-500">Página {{ $pageNumber }} / {{ $this->totalPages }}</span>
            <x-filament::button color="gray" wire:click="$set('pageNumber', {{ min($this->totalPages, $pageNumber + 1) }})" :disabled="$pageNumber >= $this->totalPages">Próxima</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>

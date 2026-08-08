<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap gap-3 mb-4 items-end">
            <div>
                <label class="text-xs text-gray-500">Modo</label>
                <select wire:model.live="mode" class="block rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="upcoming">Upcoming</option>
                    <option value="history">Histórico</option>
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">Acordo fontes</label>
                <select wire:model.live="agreementFilter" class="block rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Todos</option>
                    @foreach (['3/3','2/2','2/3','1/2','1/3','1/1'] as $opt)
                        <option value="{{ $opt }}">{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            @if ($this->hitRate)
                <div class="text-sm font-semibold text-amber-600">Acerto exclusão: {{ $this->hitRate }}</div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">Horário</th>
                        <th class="py-2 pr-3">Jogo</th>
                        <th class="py-2 pr-3">Excluir</th>
                        <th class="py-2 pr-3">Acordo</th>
                        @if ($mode === 'history')
                            <th class="py-2 pr-3">Real</th>
                            <th class="py-2 pr-3">Hit</th>
                        @endif
                        <th class="py-2 pr-3">Fav</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('d/m H:i') : '—' }}</td>
                            <td class="py-2 pr-3">
                                <div>{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</div>
                                <div class="text-xs text-gray-500">{{ $row['country'] }} · {{ $row['competition'] }}</div>
                            </td>
                            <td class="py-2 pr-3 font-semibold uppercase">{{ $row['excluded'] }}</td>
                            <td class="py-2 pr-3">{{ $row['agreementKey'] }}</td>
                            @if ($mode === 'history')
                                <td class="py-2 pr-3 uppercase">{{ $row['actual'] }}</td>
                                <td class="py-2 pr-3">{{ $row['hit'] ? '✓' : '✗' }}</td>
                            @endif
                            <td class="py-2 pr-3">
                                @if (!empty($row['country']) && !empty($row['competition']))
                                    <button type="button" class="text-amber-600"
                                        wire:click="toggleFavoriteLeague({{ json_encode($row['country']) }}, {{ json_encode($row['competition']) }})">★</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-gray-500">Sem resultados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap gap-3 mb-4 items-end">
            <div>
                <label class="text-xs text-gray-500">Mercado</label>
                <select wire:model.live="market" class="block rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    @foreach (\App\Oracly\Services\PredictionService::MARKETS as $key => $meta)
                        <option value="{{ $key }}">{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">Modo</label>
                <select wire:model.live="mode" class="block rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="upcoming">Upcoming</option>
                    <option value="history">Histórico</option>
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">Min. prob.</label>
                <input type="number" min="0" max="100" wire:model.live="minProbability" class="block w-24 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            @if ($this->hitRate)
                <div class="text-sm font-semibold text-amber-600">Acerto: {{ $this->hitRate }}</div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">Horário</th>
                        <th class="py-2 pr-3">Jogo</th>
                        <th class="py-2 pr-3">Prob.</th>
                        @if ($mode === 'history')
                            <th class="py-2 pr-3">Resultado</th>
                            <th class="py-2 pr-3">Hit</th>
                        @endif
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
                            <td class="py-2 pr-3 font-semibold">{{ number_format($row['probability'], 0) }}%</td>
                            @if ($mode === 'history')
                                <td class="py-2 pr-3">HT {{ $row['halftimeGoals'] }} · FT {{ $row['finalGoals'] }}</td>
                                <td class="py-2 pr-3">{{ $row['hit'] ? '✓' : '✗' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-gray-500">Sem resultados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

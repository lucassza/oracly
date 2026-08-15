<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Operação diária · estratégias contra">
        Lista LAY.
        <x-slot name="description">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} · uma linha para cada estratégia disponível.</x-slot>
    </x-oracly.page-header>

    <div
        x-data="{
            copied: false,
            rows: @js($rows),
            copyAll() {
                const text = this.rows.map((row) => `${new Date(row.kickoffAt).toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' })}\t${row.homeTeam} x ${row.awayTeam}\t${row.bet}`).join('\n');
                navigator.clipboard.writeText(text).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) });
            },
        }"
        class="flex justify-end"
    >
        <button type="button" x-on:click="copyAll" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">
            <x-filament::icon icon="heroicon-m-clipboard-document" class="size-4" />
            <span x-text="copied ? 'Copiado!' : 'Copiar tudo'"></span>
        </button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:bg-white/[0.03] dark:text-gray-400">
                <tr>
                    <th class="w-20 px-4 py-2">Hora</th>
                    <th class="w-44 px-4 py-2">Posição</th>
                    <th class="px-4 py-2">Partida</th>
                    <th class="w-32 px-4 py-2">Estratégia</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/[0.08]">
                @forelse ($rows as $row)
                    <tr class="odd:bg-gray-50/60 dark:odd:bg-white/[0.025]">
                        <td class="whitespace-nowrap px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">{{ \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3"><x-oracly.opportunity-rank-badge :rank="$row['rank']" /></td>
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            <div x-data="{ copied: '', copy(text, team) { navigator.clipboard.writeText(text).then(() => { this.copied = team; setTimeout(() => this.copied = '', 2000) }) } }" class="flex flex-wrap items-center gap-x-1.5">
                                <span class="inline-flex items-center gap-1">
                                    {{ $row['homeTeam'] }}
                                    <button type="button" x-on:click="copy(@js($row['homeTeam']), 'home')" x-bind:aria-label="copied === 'home' ? 'Time da casa copiado' : 'Copiar time da casa'" x-bind:title="copied === 'home' ? 'Copiado!' : 'Copiar time da casa'" class="inline-flex size-5 items-center justify-center rounded text-gray-400 transition hover:bg-gray-200 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                        <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === 'home'" />
                                        <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== 'home'" />
                                    </button>
                                </span>
                                <span class="text-gray-400">x</span>
                                <span class="inline-flex items-center gap-1">
                                    {{ $row['awayTeam'] }}
                                    <button type="button" x-on:click="copy(@js($row['awayTeam']), 'away')" x-bind:aria-label="copied === 'away' ? 'Time visitante copiado' : 'Copiar time visitante'" x-bind:title="copied === 'away' ? 'Copiado!' : 'Copiar time visitante'" class="inline-flex size-5 items-center justify-center rounded text-gray-400 transition hover:bg-gray-200 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                        <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === 'away'" />
                                        <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== 'away'" />
                                    </button>
                                </span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800 dark:bg-amber-400/20 dark:text-amber-200">{{ $row['bet'] }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">Nenhuma estratégia LAY para esta data.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>

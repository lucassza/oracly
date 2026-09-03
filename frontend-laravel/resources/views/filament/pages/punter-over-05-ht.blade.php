<x-filament-panels::page>
    <style>
        .oracly-history-bar {
            background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
        }

        .oracly-history-bar--rate {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }
    </style>

    <x-oracly.page-header eyebrow="Operação diária · dados Punter">
        Over 0.5 HT — Punter.
        <x-slot name="description">
            @if ($mode === 'upcoming')
                {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} · jogos que o Punter recomenda pra Over HT
                (única fonte disponível pra jogo futuro — não tem odd de 1º tempo no painel de jogos futuros).
            @else
                Histórico apurado por corte de odd de mercado (`odds_1st_half_over05`) — o sinal que
                realmente discrimina, bem mais forte que a recomendação do Punter sozinha.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />

    @if ($mode === 'history')
        <x-oracly.chip-group :options="$this::PROFILE_OPTIONS" :active="$profileFilter" method="setProfileFilter" />
    @endif

    @if ($mode === 'upcoming')
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
                <button type="button" wire:click="previousDay" class="rounded-lg px-3 py-1.5 font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-white/10">Dia anterior</button>
                <span class="min-w-24 text-center font-semibold text-gray-950 dark:text-white">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</span>
                <button type="button" wire:click="nextDay" class="rounded-lg px-3 py-1.5 font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-white/10">Próximo dia</button>
            </div>
        </div>
    @else
        <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-3 dark:border-white/10 dark:bg-white/[0.04]">
            <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                Data inicial
                <input type="date" wire:model.live="historyFrom" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
            </label>
            <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                Data final
                <input type="date" wire:model.live="historyTo" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
            </label>
            <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                Competição
                <select wire:model.live="historyCompetitionFilter" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                    <option value="all">Todas</option>
                    @foreach ($this->historyCompetitions as $competition => $label)
                        <option value="{{ $competition }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <x-oracly.stat-tile :value="$this->historyStats['entries']" label="Entradas históricas" active />
            <x-oracly.stat-tile :value="$this->historyStats['wins']" label="Greens" />
            <x-oracly.stat-tile :value="$this->historyStats['reds']" label="Reds" />
            <x-oracly.stat-tile :value="$this->historyStats['hitRate'] !== null ? number_format($this->historyStats['hitRate'], 1).'%' : '—'" label="Assertividade" accent />
        </div>

        @if (count($this->historyChartDays))
            @php
                $maxEntries = max(array_map(fn (array $day): int => $day['entries'], $this->historyChartDays));
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Gráfico histórico das entradas</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Últimos {{ count($this->historyChartDays) }} dias com entradas liquidadas.</p>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">barra = volume · faixa verde = assertividade</div>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                    @foreach ($this->historyChartDays as $day)
                        <div class="rounded-lg border border-gray-200/80 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/[0.02]">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $day['label'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $day['entries'] }} entr.</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="oracly-history-bar h-2 rounded-full" style="width: {{ $maxEntries > 0 ? max(10, (int) round(($day['entries'] / $maxEntries) * 100)) : 0 }}%"></div>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="oracly-history-bar--rate h-2 rounded-full" style="width: {{ $day['hitRate'] !== null ? round($day['hitRate']) : 0 }}%"></div>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-xs">
                                <span class="text-emerald-700 dark:text-emerald-300">{{ $day['wins'] }}G</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $day['hitRate'] !== null ? number_format($day['hitRate'], 0).'%' : '—' }}</span>
                                <span class="text-rose-700 dark:text-rose-300">{{ $day['reds'] }}R</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:bg-white/[0.03] dark:text-gray-400">
                <tr>
                    <th class="w-24 px-4 py-2">Data</th>
                    <th class="px-4 py-2">Partida</th>
                    <th class="w-56 px-4 py-2">Aposta</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/[0.08]">
                @php $items = $mode === 'upcoming' ? $this->rows : $this->pagedHistoryRows; @endphp
                @forelse ($items as $row)
                    <tr class="odd:bg-gray-100 dark:odd:bg-white/[0.06]">
                        <td class="whitespace-nowrap px-4 py-3 font-bold text-amber-700 dark:text-amber-300">
                            {{ \Carbon\Carbon::parse($row['dateBrasilia'])->format('d/m') }}
                        </td>
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            <div x-data="{ copied: '', copy(text, team) { navigator.clipboard.writeText(text).then(() => { this.copied = team; setTimeout(() => this.copied = '', 2000) }) } }" class="space-y-1">
                                <div class="flex flex-wrap items-center gap-x-1.5">
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
                                @if (!empty($row['competition']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', $row['competition']) }}</div>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800 dark:bg-amber-400/20 dark:text-amber-200">OVER 0.5 HT</span>
                                @if ($mode === 'history')
                                    <span class="text-xs text-gray-500 dark:text-gray-400">odd {{ $row['oddOver05Ht'] !== null ? number_format($row['oddOver05Ht'], 2) : '—' }}</span>
                                    @if ($row['punterAgrees'])
                                        <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-800 dark:bg-sky-400/15 dark:text-sky-200">Punter concorda</span>
                                    @endif
                                    <x-oracly.result-badge :hit="$row['hit']" />
                                @else
                                    <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-800 dark:bg-sky-400/15 dark:text-sky-200">Recomendado pelo Punter</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ $mode === 'history' ? 'Nenhuma entrada histórica para os filtros atuais.' : 'Nenhum pick Over 0.5 HT para esta data.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($mode === 'history' && $this->historyPagination['total'] > 0)
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Exibindo {{ $this->historyPagination['from'] }}–{{ $this->historyPagination['to'] }} de {{ $this->historyPagination['total'] }} partidas
            </p>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="previousHistoryPage" @disabled($this->historyPagination['page'] === 1) class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10">Anterior</button>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Página {{ $this->historyPagination['page'] }} de {{ $this->historyPagination['lastPage'] }}</span>
                <button type="button" wire:click="nextHistoryPage" @disabled($this->historyPagination['page'] === $this->historyPagination['lastPage']) class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10">Próxima</button>
            </div>
        </div>
    @endif
</x-filament-panels::page>

<x-filament-panels::page>
    <style>
        .oracly-hour-filter__button {
            background: #f3f4f6;
            color: #374151;
        }

        .oracly-hour-filter__button.is-active {
            background: #fbbf24;
            color: #111827;
        }

        .dark .oracly-hour-filter__button {
            background: #334155 !important;
            color: #f8fafc !important;
        }

        .dark .oracly-hour-filter__button.is-active {
            background: #fbbf24 !important;
            color: #111827 !important;
        }

        .oracly-history-bar {
            background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
        }

        .oracly-history-bar--rate {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }
    </style>

    <x-oracly.page-header eyebrow="Operação diária · dados Punter">
        Lista LAY — Punter.
        <x-slot name="description">
            @if ($mode === 'upcoming')
                {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} ·
                @if ($market === 'lay_2x2_0x1')
                    placar exato apurado pelo próprio Punter.
                @elseif ($market === 'lay_scores')
                    placar exato via Poisson (AgainstOneGoalStrategy e subclasses), médias de gols do Punter.
                @else
                    critério próprio por odd do favorito.
                @endif
            @else
                Histórico apurado direto do schema <code>punter</code> — sem sobreposição relevante com o SokkerPRO.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MARKET_OPTIONS" :active="$market" method="setMarket" />
    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />

    @if ($market === 'lay_2x2_0x1')
        <x-oracly.chip-group :options="$this::RADAR_OPTIONS" :active="$radarFilter" method="setRadarFilter" />
    @elseif ($market === 'lay_casa_fora')
        <x-oracly.chip-group :options="$this::PROFILE_OPTIONS" :active="$profileFilter" method="setProfileFilter" />
    @endif

    @if ($mode === 'history' && in_array($market, ['lay_casa_fora', 'lay_scores'], true))
        <x-oracly.chip-group :options="$this::PERIOD_OPTIONS" :active="$periodFilter" method="setPeriodFilter" />
    @endif

    @if ($market === 'lay_2x2_0x1')
        <label class="inline-flex w-fit items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
            <input type="checkbox" wire:click="toggleOnlyTopOfHour" @checked($onlyTopOfHour) class="rounded border-gray-300 text-amber-500 focus:ring-amber-500 dark:border-white/20 dark:bg-white/10" />
            <span class="font-semibold text-gray-700 dark:text-gray-200">Só os 3 melhores da hora</span>
            <span class="text-xs text-gray-400">(medido: +0,5pp de assertividade cortando 37% do volume)</span>
        </label>
    @endif

    @if ($mode === 'upcoming')
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
                <button type="button" wire:click="previousDay" class="rounded-lg px-3 py-1.5 font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-white/10">Dia anterior</button>
                <span class="min-w-24 text-center font-semibold text-gray-950 dark:text-white">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</span>
                <button type="button" wire:click="nextDay" class="rounded-lg px-3 py-1.5 font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-white/10">Próximo dia</button>
            </div>
        </div>

        @if ($market === 'lay_2x2_0x1')
            <div class="oracly-hour-filter -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <div class="flex w-max gap-2 pb-1">
                    <button type="button" wire:click="setHourFilter('all')" class="oracly-hour-filter__button {{ $hourFilter === 'all' ? 'is-active' : '' }} rounded-full px-3 py-1.5 text-sm font-semibold transition hover:opacity-85">Todos</button>
                    @foreach ($this->hours as $hour)
                        <button type="button" wire:click="setHourFilter('{{ $hour }}')" class="oracly-hour-filter__button {{ $hourFilter === $hour ? 'is-active' : '' }} rounded-full px-3 py-1.5 text-sm font-semibold transition hover:opacity-85">{{ $hour }}</button>
                    @endforeach
                </div>
            </div>
        @endif
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

        @if (count($this->historyStrategyStats))
            <section>
                <div class="mb-3">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Assertividade por aposta</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Resultados conforme os filtros selecionados.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($this->historyStrategyStats as $strategy)
                        <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
                            <div class="flex items-start justify-between gap-3">
                                <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800 dark:bg-amber-400/20 dark:text-amber-200">{{ $strategy['strategy'] }}</span>
                                <span class="text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ number_format($strategy['hitRate'] ?? 0, 1) }}%</span>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="oracly-history-bar--rate h-2 rounded-full" style="width: {{ round($strategy['hitRate'] ?? 0) }}%"></div>
                            </div>
                            <div class="mt-3 flex items-center justify-between text-xs">
                                <span class="text-gray-500 dark:text-gray-400">{{ $strategy['entries'] }} entradas</span>
                                <span class="text-emerald-700 dark:text-emerald-300">{{ $strategy['wins'] }}G</span>
                                <span class="text-rose-700 dark:text-rose-300">{{ $strategy['reds'] }}R</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

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
                    <th class="w-24 px-4 py-2">{{ $mode === 'history' || $market !== 'lay_2x2_0x1' ? 'Data' : 'Hora' }}</th>
                    <th class="px-4 py-2">Partida</th>
                    <th class="w-56 px-4 py-2">Aposta</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/[0.08]">
                @php $cards = $mode === 'upcoming' ? $this->groupedRows : $this->pagedHistoryRows; @endphp
                @forelse ($cards as $card)
                    <tr class="odd:bg-gray-100 dark:odd:bg-white/[0.06]">
                        <td class="whitespace-nowrap px-4 py-3 font-bold text-amber-700 dark:text-amber-300">
                            @if ($card['kickoffAt'])
                                {{ \Carbon\Carbon::parse($card['kickoffAt'])->timezone('America/Sao_Paulo')->format($mode === 'history' ? 'd/m H:i' : 'H:i') }}
                            @else
                                {{ \Carbon\Carbon::parse($card['dateBrasilia'])->format('d/m') }}
                            @endif
                        </td>
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            <div x-data="{ copied: '', copy(text, team) { navigator.clipboard.writeText(text).then(() => { this.copied = team; setTimeout(() => this.copied = '', 2000) }) } }" class="space-y-1">
                                <div class="flex flex-wrap items-center gap-x-1.5">
                                    <span class="inline-flex items-center gap-1">
                                        {{ $card['homeTeam'] }}
                                        <button type="button" x-on:click="copy(@js($card['homeTeam']), 'home')" x-bind:aria-label="copied === 'home' ? 'Time da casa copiado' : 'Copiar time da casa'" x-bind:title="copied === 'home' ? 'Copiado!' : 'Copiar time da casa'" class="inline-flex size-5 items-center justify-center rounded text-gray-400 transition hover:bg-gray-200 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                            <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === 'home'" />
                                            <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== 'home'" />
                                        </button>
                                    </span>
                                    <span class="text-gray-400">x</span>
                                    <span class="inline-flex items-center gap-1">
                                        {{ $card['awayTeam'] }}
                                        <button type="button" x-on:click="copy(@js($card['awayTeam']), 'away')" x-bind:aria-label="copied === 'away' ? 'Time visitante copiado' : 'Copiar time visitante'" x-bind:title="copied === 'away' ? 'Copiado!' : 'Copiar time visitante'" class="inline-flex size-5 items-center justify-center rounded text-gray-400 transition hover:bg-gray-200 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                            <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === 'away'" />
                                            <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== 'away'" />
                                        </button>
                                    </span>
                                </div>
                                @if (!empty($card['competition']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ !empty($card['country']) ? $card['country'].' · ' : '' }}{{ str_replace('_', ' ', $card['competition']) }}
                                    </div>
                                @endif
                                @if ($mode === 'history' && $card['ftHome'] !== null && $card['ftAway'] !== null)
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <span>{{ $card['htHome'] !== null ? 'HT '.$card['htHome'].'-'.$card['htAway'] : 'HT —' }}</span>
                                        <span>FT {{ $card['ftHome'] }}-{{ $card['ftAway'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-1.5">
                                @foreach ($card['bets'] as $bet)
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800 dark:bg-amber-400/20 dark:text-amber-200">{{ $bet['bet'] }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $bet['betMeta'] }}</span>
                                        <x-oracly.opportunity-rank-badge :rank="$bet['rank']" />
                                        @if ($mode === 'history')
                                            <x-oracly.result-badge :hit="$bet['hit']" />
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ $mode === 'history' ? 'Nenhuma entrada histórica para os filtros atuais.' : 'Nenhum pick LAY Punter para esta data.' }}
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

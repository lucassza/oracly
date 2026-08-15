<x-filament-panels::page>
    <style>
        .oracly-decision-card {
            position: relative;
            overflow: hidden;
        }

        .oracly-decision-card::before {
            position: absolute;
            inset: 0 auto 0 0;
            width: .25rem;
            background: #f59e0b;
            content: '';
        }

        .dark .oracly-decision-card {
            background: #111827 !important;
            border-color: #334155 !important;
        }

        .dark .oracly-decision-card time {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        .dark .oracly-decision-card .border-gray-100 {
            border-color: rgba(148, 163, 184, .2) !important;
        }

        .dark .oracly-decision-card .bg-gray-50\/70 {
            background: #0f172a !important;
        }

        .dark .oracly-decision-card .bg-amber-50\/80 {
            background: rgba(245, 158, 11, .14) !important;
            border-color: rgba(251, 191, 36, .45) !important;
        }
    </style>
    <x-oracly.page-header eyebrow="Radar diário · ligas favoritas">
        Decisões do dia.
        <x-slot name="description">Oportunidades que ficaram no Top 3 de uma estratégia, agrupadas por partida nas suas ligas favoritas.</x-slot>
    </x-oracly.page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <x-oracly.stat-tile :value="count($cards)" label="Partidas no radar" active />
        <x-oracly.stat-tile :value="collect($cards)->sum(fn (array $card) => count($card['actions']))" label="Sinais em destaque" />
        <x-oracly.stat-tile class="col-span-2 lg:col-span-1" :value="count($favoriteLeagues)" label="Ligas selecionadas" accent />
    </div>

    @if (count($cards))
        <div class="space-y-10">
            @foreach ($this->cardGroups as $group)
                <section>
                    <div class="mb-4 flex items-end justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-950 dark:text-white">{{ $group['title'] }}</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $group['description'] }}</p>
                        </div>
                        <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ count($group['cards']) }}</span>
                    </div>
                    @if (count($group['cards']))
                        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                            @foreach ($group['cards'] as $card)
                                <article class="oracly-decision-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-white/[0.04]">
                                    <div class="mb-5 flex items-start justify-between gap-4 pl-2">
                                        <div class="min-w-0">
                                            <div class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-amber-600 uppercase dark:text-amber-300">
                                                <span class="rounded-full bg-amber-100 px-2 py-1 dark:bg-amber-400/10">★ Favorita</span>
                                                <span>{{ count($card['actions']) }} {{ count($card['actions']) === 1 ? 'sinal' : 'sinais' }}</span>
                                            </div>
                                            <div
                                                x-data="{ copied: '', copy(text, type) { navigator.clipboard.writeText(text).then(() => { this.copied = type; setTimeout(() => this.copied = '', 2000) }) } }"
                                                class="flex items-start gap-2"
                                            >
                                                <h3 class="flex flex-wrap items-center gap-x-1.5 text-xl font-bold leading-snug text-gray-950 dark:text-white">
                                                    <span class="inline-flex items-center gap-1">
                                                        {{ $card['homeTeam'] }}
                                                        <button type="button" x-on:click="copy(@js($card['homeTeam']), 'home')" x-bind:aria-label="copied === 'home' ? 'Time da casa copiado' : 'Copiar time da casa'" x-bind:title="copied === 'home' ? 'Copiado!' : 'Copiar time da casa'" class="inline-flex size-5 items-center justify-center rounded text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                                            <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === 'home'" />
                                                            <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== 'home'" />
                                                        </button>
                                                    </span>
                                                    <span class="font-medium text-gray-400 dark:text-gray-500">×</span>
                                                    <span class="inline-flex items-center gap-1">
                                                        {{ $card['awayTeam'] }}
                                                        <button type="button" x-on:click="copy(@js($card['awayTeam']), 'away')" x-bind:aria-label="copied === 'away' ? 'Time visitante copiado' : 'Copiar time visitante'" x-bind:title="copied === 'away' ? 'Copiado!' : 'Copiar time visitante'" class="inline-flex size-5 items-center justify-center rounded text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                                            <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === 'away'" />
                                                            <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== 'away'" />
                                                        </button>
                                                    </span>
                                                </h3>
                                                <div class="mt-0.5 flex shrink-0 items-center gap-1">
                                                    <button type="button" x-on:click="copy(@js($card['homeTeam'].' × '.$card['awayTeam']), 'match')" x-bind:aria-label="copied === 'match' ? 'Partida copiada' : 'Copiar partida'" x-bind:title="copied === 'match' ? 'Copiado!' : 'Copiar partida'" class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-[10px] font-bold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                                        <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3" />
                                                        <span x-text="copied === 'match' ? 'Copiado!' : 'Partida'"></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ $card['country'] }} <span aria-hidden="true">·</span> {{ $card['competition'] }}</p>
                                        </div>
                                        @if ($group['key'] === 'past' && $card['homeScore'] !== null && $card['awayScore'] !== null)
                                            <div class="shrink-0 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-center text-gray-900 dark:border-white/10 dark:bg-white/[0.06] dark:text-white">
                                                <div class="text-[10px] font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">FT</div>
                                                <div class="text-sm font-bold">{{ $card['homeScore'] }}–{{ $card['awayScore'] }}</div>
                                            </div>
                                        @else
                                            <time class="shrink-0 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-center text-sm font-bold text-gray-900 dark:border-white/10 dark:bg-white/[0.06] dark:text-white">
                                                {{ $card['kickoffAt'] ? \Carbon\Carbon::parse($card['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') : '—' }}
                                            </time>
                                        @endif
                                    </div>
                                    <div class="space-y-2 border-t border-gray-100 pt-3 dark:border-white/10">
                                        @foreach ($card['actions'] as $action)
                                            <div class="{{ $loop->first ? 'border-amber-300 bg-amber-50/80 dark:border-amber-400/40 dark:bg-amber-400/10' : 'border-gray-100 bg-gray-50/70 dark:border-white/[0.08] dark:bg-white/[0.03]' }} rounded-xl border p-3">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-1 text-base font-bold text-gray-950 dark:text-white">
                                                        <span>{{ $action['bet'] }}</span>
                                                        <x-oracly.opportunity-rank-badge :rank="$action['rank']" />
                                                    </div>
                                                    <div class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">Estratégia: {{ $action['label'] }} <span aria-hidden="true">·</span> {{ $action['detail'] }}</div>
                                                    <div class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">Assertividade histórica: {{ $action['historicalHitRate'] !== null ? number_format($action['historicalHitRate'], 1).'%' : '—' }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <div class="rounded-xl border border-dashed border-gray-300 px-5 py-4 text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">Nenhuma partida nesta seção.</div>
                    @endif
                </section>
            @endforeach
        </div>
    @else
        <div class="rounded-2xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
            @if (count($favoriteLeagues) === 0)
                <p class="font-semibold text-gray-900 dark:text-white">Nenhuma liga favorita selecionada.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Marque suas ligas na página de favoritos para preencher este radar.</p>
            @else
                <p class="font-semibold text-gray-900 dark:text-white">Nenhuma oportunidade elegível para este dia.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Não há partidas das suas ligas favoritas no Top 3 das estratégias.</p>
            @endif
        </div>
    @endif
</x-filament-panels::page>

<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Radar diário · decisão rápida">
        Decisões do dia por partida.
        <x-slot name="description">Aparecem todas as partidas que ficaram no Top 3 de pelo menos uma estratégia. Cada card reúne os sinais classificados para a mesma partida.</x-slot>
    </x-oracly.page-header>

    <div class="grid gap-4 xl:grid-cols-3">
        @forelse ($cards as $index => $card)
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-950/80 dark:shadow-black/20">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-300">{{ count($card['actions']) }} estratégia(s) em destaque</div>
                        <h2 class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $card['homeTeam'] }} x {{ $card['awayTeam'] }}</h2>
                        <p class="text-sm text-gray-500 dark:text-slate-300">{{ $card['country'] }} · {{ $card['competition'] }}</p>
                    </div>
                    <time class="rounded-lg bg-gray-100 px-2 py-1 text-sm font-semibold text-gray-800 dark:bg-slate-800 dark:text-slate-100">{{ $card['kickoffAt'] ? \Carbon\Carbon::parse($card['kickoffAt'])->timezone('America/Sao_Paulo')->format('H:i') : '—' }}</time>
                </div>
                <div class="space-y-2">
                    @foreach ($card['actions'] as $action)
                        <div class="rounded-xl border border-gray-100 px-3 py-2 dark:border-slate-700 {{ $loop->first ? 'bg-amber-50 dark:border-amber-400/30 dark:bg-amber-400/15' : 'bg-gray-50 dark:bg-slate-900/80' }}">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ ['👑', '🔥', '●'][$action['rank'] - 1] }} {{ $action['label'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-slate-300">{{ $action['detail'] }}</div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-xs text-gray-500 dark:text-slate-400">Confirme escalações, mercado e cotação real antes de entrar.</p>
            </article>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-slate-700 dark:text-slate-300">Nenhuma oportunidade elegível para este dia.</div>
        @endforelse
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Configuração · salvo no Postgres">
        Países e ligas favoritas.

        <x-slot name="description">
            {{ count($favorites['countries']) }} país(es) e {{ count($favorites['leagues']) }} liga(s) marcadas.
            Usado pra destacar (★) e filtrar jogos na aba Hoje.
        </x-slot>
    </x-oracly.page-header>

    <x-filament::input.wrapper>
        <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar país ou liga…" />
    </x-filament::input.wrapper>

    <div class="space-y-3">
        @forelse ($this->groupedLeagues as $country => $leagues)
            <div class="oracly-tile">
                <div class="mb-3 flex items-center justify-between">
                    <strong class="text-gray-950 dark:text-white">{{ $country }}</strong>
                    <button
                        type="button"
                        wire:click="toggleCountry({{ json_encode($country) }})"
                        class="oracly-chip {{ in_array($country, $favorites['countries'], true) ? 'oracly-chip--active' : '' }}"
                    >
                        {{ in_array($country, $favorites['countries'], true) ? '★ País favorito' : '☆ Favoritar país' }}
                    </button>
                </div>
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach ($leagues as $league)
                        @php $key = $league['country'].'::'.$league['competition']; @endphp
                        <button
                            type="button"
                            class="flex items-center justify-between rounded-md px-2 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                            wire:click="toggleLeague({{ json_encode($league['country']) }}, {{ json_encode($league['competition']) }})"
                        >
                            <span class="{{ in_array($key, $favorites['leagues'], true) ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300' }}">
                                {{ in_array($key, $favorites['leagues'], true) ? '★' : '☆' }}
                                {{ $league['competition'] }}
                            </span>
                            @if ($league['division'])
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $league['division'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-gray-500 dark:text-gray-400">Nenhuma liga encontrada.</p>
        @endforelse
    </div>
</x-filament-panels::page>

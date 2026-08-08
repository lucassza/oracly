<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Países e ligas</x-slot>
        <x-slot name="description">Persistidos no Postgres (favoritos compartilhados).</x-slot>

        <div class="mb-4">
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar país ou liga…" />
            </x-filament::input.wrapper>
        </div>

        <div class="space-y-4">
            @forelse ($this->groupedLeagues as $country => $leagues)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <strong>{{ $country }}</strong>
                        <x-filament::button size="sm" color="{{ in_array($country, $favorites['countries'], true) ? 'warning' : 'gray' }}" wire:click="toggleCountry({{ json_encode($country) }})">
                            {{ in_array($country, $favorites['countries'], true) ? 'País favorito' : 'Favoritar país' }}
                        </x-filament::button>
                    </div>
                    <div class="grid gap-2 md:grid-cols-2">
                        @foreach ($leagues as $league)
                            @php $key = $league['country'].'::'.$league['competition']; @endphp
                            <button type="button" class="text-left text-sm px-2 py-1 rounded hover:bg-gray-50 dark:hover:bg-gray-800"
                                wire:click="toggleLeague({{ json_encode($league['country']) }}, {{ json_encode($league['competition']) }})">
                                <span class="{{ in_array($key, $favorites['leagues'], true) ? 'text-amber-600 font-semibold' : '' }}">
                                    {{ in_array($key, $favorites['leagues'], true) ? '★' : '☆' }}
                                    {{ $league['competition'] }}
                                </span>
                                @if ($league['division'])
                                    <span class="text-xs text-gray-500 ml-1">{{ $league['division'] }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-gray-500">Nenhuma liga encontrada.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>

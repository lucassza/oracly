@props(['home', 'away'])

{{-- Par de times com botão de copiar em cada nome, para colar na exchange sem digitar. --}}
<span
    x-data="{ copied: '', copy(text, which) { navigator.clipboard.writeText(text).then(() => { this.copied = which; setTimeout(() => this.copied = '', 2000) }) } }"
    class="oracly-team-pair"
>
    @foreach (['home' => $home, 'away' => $away] as $which => $team)
        @if ($which === 'away')<span class="oracly-team-pair__vs">x</span>@endif
        <span class="oracly-team-pair__team">
            {{ $team }}
            <button
                type="button"
                x-on:click="copy(@js($team), '{{ $which }}')"
                x-bind:title="copied === '{{ $which }}' ? 'Copiado!' : 'Copiar {{ $team }}'"
                x-bind:aria-label="copied === '{{ $which }}' ? 'Nome copiado' : 'Copiar {{ $team }}'"
                class="oracly-copy"
            >
                <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-emerald-500" x-cloak x-show="copied === '{{ $which }}'" />
                <x-filament::icon icon="heroicon-m-clipboard-document" class="size-3.5" x-show="copied !== '{{ $which }}'" />
            </button>
        </span>
    @endforeach
</span>

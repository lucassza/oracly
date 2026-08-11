@props(['options', 'active', 'method'])

<div class="oracly-chip-group">
    @foreach ($options as $value => $label)
        <button
            type="button"
            wire:click="{{ $method }}({{ json_encode($value) }})"
            wire:loading.attr="disabled"
            wire:target="{{ $method }}"
            class="oracly-chip {{ (string) $active === (string) $value ? 'oracly-chip--active' : '' }}"
        >
            <span wire:loading.remove wire:target="{{ $method }}">{{ $label }}</span>
            <span wire:loading wire:target="{{ $method }}">Carregando…</span>
        </button>
    @endforeach
</div>

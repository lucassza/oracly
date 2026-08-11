@props(['value', 'label', 'active' => false, 'accent' => false, 'hero' => false])

<div {{ $attributes->class([
    'oracly-tile',
    'oracly-tile--active' => $active,
    'oracly-tile--accent' => $accent,
    'oracly-tile--hero' => $hero,
]) }}>
    <div class="oracly-tile-value">{{ $value }}</div>
    <div class="oracly-tile-label">{{ $label }}</div>
    {{ $slot }}
</div>

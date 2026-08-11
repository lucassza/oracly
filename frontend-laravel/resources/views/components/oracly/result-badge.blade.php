@props(['hit' => null, 'label' => null])

@php
    $variant = $hit === null ? 'neutral' : ($hit ? 'win' : 'loss');
    $text = $label ?? ($hit === null ? '—' : ($hit ? 'Acerto' : 'Erro'));
@endphp

<span class="oracly-badge oracly-badge--{{ $variant }}">{{ $text }}</span>

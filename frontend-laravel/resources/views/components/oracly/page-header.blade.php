@props(['eyebrow' => null])

<div class="oracly-page-header">
    <div>
        @if ($eyebrow)
            <p class="oracly-eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="oracly-title">{{ $slot }}</h1>
    </div>
    @isset($description)
        <div class="oracly-description">{{ $description }}</div>
    @endisset
</div>

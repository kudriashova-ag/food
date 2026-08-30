@props(['dish', 'size' => 'md'])

@php
    $imageUrl = $dish->primaryPhoto
        ? Storage::disk('public')->url($dish->primaryPhoto->path)
        : null;

    $allergenNames = $dish->allergens->pluck('name')->implode('|');

    // 'sm' — компактний ряд, 'md' — звичайна картка страви,
    // 'grid' — на всю ширину колонки сітки (квадрат), для комплексу.
    $sizeClasses = match ($size) {
        'sm' => 'h-12 w-12',
        'grid' => 'aspect-square w-full',
        default => 'h-16 w-16 sm:h-20 sm:w-20',
    };

    $wrapperClasses = $size === 'grid' ? 'w-full' : 'shrink-0';
@endphp

<button type="button" data-dish-trigger
        data-dish-image="{{ $imageUrl }}"
        data-dish-name="{{ $dish->name }}"
        data-dish-portion="{{ $dish->portion }}"
        data-dish-description="{{ $dish->description }}"
        data-dish-allergens="{{ $allergenNames }}"
        title="{{ $dish->name }}"
        {{ $attributes->class([$wrapperClasses, 'rounded-xl ring-1 ring-ink-200 transition hover:ring-deep-500']) }}>
    @if ($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $dish->name }}" loading="lazy"
             class="{{ $sizeClasses }} rounded-xl object-cover">
    @else
        <div class="{{ $sizeClasses }} flex items-center justify-center rounded-xl bg-ink-100">
            <svg class="h-7 w-7 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 2v7c0 1.1.9 2 2 2h1a2 2 0 0 0 2-2V2"/><path d="M6 2v20"/>
                <path d="M18 2c-1.7 0-3 2.2-3 5s.7 4 3 4v11"/>
            </svg>
        </div>
    @endif
</button>

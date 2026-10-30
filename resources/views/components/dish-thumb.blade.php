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

{{-- Без фото нічого не виводимо: заглушка лише займала місце. --}}
@if ($imageUrl)
<button type="button" data-dish-trigger
        data-dish-image="{{ $imageUrl }}"
        data-dish-name="{{ $dish->name }}"
        data-dish-portion="{{ $dish->portion }}"
        data-dish-description="{{ $dish->description }}"
        data-dish-allergens="{{ $allergenNames }}"
        title="{{ $dish->name }}"
        {{ $attributes->class([$wrapperClasses, 'rounded-xl ring-1 ring-ink-200 transition hover:ring-deep-500']) }}>
    <img src="{{ $imageUrl }}" alt="{{ $dish->name }}" loading="lazy"
         class="{{ $sizeClasses }} rounded-xl object-cover">
</button>
@endif

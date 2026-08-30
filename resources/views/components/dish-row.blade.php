@props(['dish'])

<div class="flex min-w-0 flex-1 items-center gap-3">
    <x-dish-thumb :dish="$dish" />

    <div class="min-w-0">
        <div class="font-medium leading-snug">{{ $dish->name }}</div>

        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm">
            <span class="font-semibold text-deep-700 tabular-nums">
                {{ number_format((float) $dish->price, 2, ',', ' ') }} грн
            </span>

            @if ($dish->portion)
                <span class="text-ink-400">{{ $dish->portion }}</span>
            @endif
        </div>

        @if ($dish->allergens->isNotEmpty())
            <div class="mt-1.5 flex flex-wrap gap-1">
                @foreach ($dish->allergens as $allergen)
                    <span class="badge bg-amber-50 text-amber-800">{{ $allergen->name }}</span>
                @endforeach
            </div>
        @endif
    </div>
</div>

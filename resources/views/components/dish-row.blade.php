@props(['dish'])

<div class="flex min-w-0 flex-1 items-center gap-3">
    @if ($dish->primaryPhoto)
        <img src="{{ Storage::disk('public')->url($dish->primaryPhoto->path) }}" alt=""
             loading="lazy"
             class="h-16 w-16 shrink-0 rounded-xl object-cover ring-1 ring-ink-200 sm:h-20 sm:w-20">
    @else
        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-ink-100 sm:h-20 sm:w-20">
            <svg class="h-7 w-7 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 2v7c0 1.1.9 2 2 2h1a2 2 0 0 0 2-2V2"/><path d="M6 2v20"/>
                <path d="M18 2c-1.7 0-3 2.2-3 5s.7 4 3 4v11"/>
            </svg>
        </div>
    @endif

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

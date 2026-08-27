@props(['dish'])

<div class="flex min-w-0 flex-1 items-center gap-3">
    @if ($dish->primaryPhoto)
        <img src="{{ Storage::disk('public')->url($dish->primaryPhoto->path) }}" alt=""
             loading="lazy" class="h-16 w-16 shrink-0 rounded-lg object-cover sm:h-20 sm:w-20">
    @endif

    <div class="min-w-0">
        <div class="truncate text-sm font-medium">{{ $dish->name }}</div>

        <div class="text-xs text-zinc-500">
            {{ number_format((float) $dish->price, 2, ',', ' ') }} грн
            @if ($dish->portion)
                · {{ $dish->portion }}
            @endif
        </div>

        @if ($dish->allergens->isNotEmpty())
            <div class="mt-1 flex flex-wrap gap-1">
                @foreach ($dish->allergens as $allergen)
                    <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">
                        {{ $allergen->name }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</div>

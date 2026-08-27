{{-- Плашка кошика в шапці. Без JS — звичайне посилання на /cart. --}}
<a href="{{ route('cart') }}" data-cart-open
   @class([
       'flex items-center gap-2.5 rounded-full px-4 py-2 font-bold transition',
       'bg-brand-500 text-deep-900 hover:bg-brand-400' => $count > 0,
       'bg-white/10 text-white/70 hover:bg-white/20' => $count === 0,
   ])>
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>
        <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
    </svg>

    <span class="text-sm">Кошик · {{ $count }}</span>

    @if ($count > 0)
        <span class="text-sm tabular-nums">{{ number_format($total, 2, ',', ' ') }} ₴</span>
    @endif
</a>

<div data-cart-overlay
     class="pointer-events-none fixed inset-0 z-30 bg-black/40 opacity-0 transition-opacity duration-200"></div>

<div data-cart-drawer
     class="fixed inset-x-0 top-0 z-40 max-h-[85vh] -translate-y-full overflow-y-auto bg-white shadow-2xl transition-transform duration-300">
    <div class="mx-auto max-w-5xl px-4 py-5">
        <div class="mb-4 flex items-center justify-between gap-4">
            <h2 class="text-xl font-bold">Спільний кошик</h2>

            <button type="button" data-cart-close
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        @forelse ($groups as $group)
            <div class="mb-4 last:mb-0">
                <div class="mb-2 flex items-baseline gap-2">
                    <h3 class="font-semibold">{{ $group['supplier']->name }}</h3>
                    <span class="text-sm text-ink-500 tabular-nums">
                        {{ number_format($group['total'], 2, ',', ' ') }} ₴
                    </span>
                </div>

                @foreach ($group['dates'] as $date)
                    <div class="mb-2 rounded-xl border border-ink-200 px-3.5 py-2.5">
                        <div class="mb-1 text-xs font-semibold uppercase tracking-wider text-ink-400">
                            {{ $date['date']->translatedFormat('D, d.m') }}
                        </div>

                        <ul class="space-y-1 text-sm">
                            @foreach ($date['items'] as $item)
                                <li class="flex justify-between gap-3">
                                    <span class="min-w-0 truncate">
                                        {{ $item->dish->name }}
                                        @if ($item->quantity > 1)
                                            <span class="font-semibold">× {{ $item->quantity }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 tabular-nums">
                                        {{ number_format($item->subtotal(), 2, ',', ' ') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-ink-300 px-4 py-6 text-center text-sm text-ink-500">
                Кошик порожній — оберіть страви в постачальника.
            </p>
        @endforelse

        @if ($count > 0)
            <div class="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-ink-200 pt-4">
                <div>
                    <div class="text-xs text-ink-500">Разом</div>
                    <div class="text-2xl font-bold tabular-nums">
                        {{ number_format($total, 2, ',', ' ') }} <span class="text-base text-ink-500">₴</span>
                    </div>
                </div>

                <a href="{{ route('cart') }}" class="btn-primary">До оформлення</a>
            </div>
        @endif
    </div>
</div>

{{-- Кошик у шапці: показує кількість і суму, веде на сторінку кошика. --}}
<a href="{{ route('cart') }}"
   @class([
       'flex items-center gap-2 rounded-full px-3.5 py-2 font-bold transition sm:px-4',
       'bg-brand-500 text-deep-900 hover:bg-brand-400' => $count > 0,
       'border border-ink-300 text-ink-600 hover:bg-ink-100' => $count === 0,
   ])>
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>
        <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
    </svg>

    @if ($count > 0)
        <span class="text-sm tabular-nums">{{ number_format($total, 2, ',', ' ') }} ₴</span>
        <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-deep-700 px-1 text-[11px] text-white">
            {{ $count }}
        </span>
    @else
        <span class="hidden text-sm sm:inline">Кошик</span>
    @endif
</a>

@if ($count > 0)
    {{-- Закріплена внизу й видима постійно (ТЗ, п. 14). --}}
    <div class="fixed inset-x-0 bottom-0 z-20 border-t border-ink-200 bg-white/95 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3
                    pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            <div class="flex items-center gap-3">
                <span class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-100">
                    <svg class="h-5 w-5 text-deep-700" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>
                        <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
                    </svg>

                    <span class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full
                                 bg-deep-700 px-1 text-[11px] font-bold text-white">
                        {{ $count }}
                    </span>
                </span>

                <div class="leading-tight">
                    <div class="text-xs text-ink-500">До сплати</div>
                    <div class="text-lg font-bold tabular-nums">
                        {{ number_format($total, 2, ',', ' ') }} <span class="text-sm font-semibold text-ink-500">грн</span>
                    </div>
                </div>
            </div>

            <a href="{{ route('cart') }}" class="btn-primary">
                До кошика
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 18 6-6-6-6"/>
                </svg>
            </a>
        </div>
    </div>
@endif

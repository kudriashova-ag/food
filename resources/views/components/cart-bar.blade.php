@if ($count > 0)
    <div class="fixed inset-x-0 bottom-0 border-t border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
            <div class="text-sm">
                <div class="text-zinc-500">У кошику {{ $count }} поз.</div>
                <div class="text-base font-semibold">{{ number_format($total, 2, ',', ' ') }} грн</div>
            </div>

            <a href="{{ route('cart') }}"
               class="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-800">
                До кошика
            </a>
        </div>
    </div>
@endif

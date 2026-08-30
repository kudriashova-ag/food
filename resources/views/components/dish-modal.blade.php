{{--
    Одна спільна модалка на всю сторінку: дані підставляє JS із data-* атрибутів
    натиснутого зображення, щоб не рендерити по діалогу на кожну страву.
--}}
<dialog data-dish-modal
        class="w-full max-w-md rounded-2xl border-0 p-0 shadow-xl backdrop:bg-ink-900/50">
    <div class="relative">
        <button type="button" data-dish-modal-close
                class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-ink-500
                       shadow transition hover:bg-white hover:text-ink-900">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
            <span class="sr-only">Закрити</span>
        </button>

        <img data-dish-modal-image src="" alt=""
             class="h-56 w-full rounded-t-2xl object-cover sm:h-64">

        <div class="p-5">
            <h3 data-dish-modal-name class="text-lg font-semibold leading-snug"></h3>

            <p data-dish-modal-description class="mt-2 text-sm text-ink-600"></p>

            <div data-dish-modal-allergens class="mt-3 flex flex-wrap gap-1.5"></div>
        </div>
    </div>
</dialog>

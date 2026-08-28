@props(['align' => 'right'])

{{-- Випадне меню в шапці на <details>: без JS-залежностей, закривається
     кліком поза межами (це вже робить header-menus.js). --}}
<details {{ $attributes->merge(['class' => 'group relative']) }} data-header-menu>
    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg px-2.5 py-2 font-medium
                    text-ink-600 transition hover:bg-ink-100 [&::-webkit-details-marker]:hidden">
        {{ $trigger }}
    </summary>

    <div @class([
             'absolute z-30 mt-2 min-w-52 overflow-hidden rounded-xl border border-ink-200 bg-white py-1 shadow-lg',
             'right-0' => $align === 'right',
             'left-0' => $align === 'left',
         ])>
        {{ $slot }}
    </div>
</details>

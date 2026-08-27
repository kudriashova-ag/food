@props(['class' => 'h-9 w-auto'])

@php
    // Логотип кладеться в public_html/images/. Формат будь-який із перелічених,
    // svg найкращий — не втрачає чіткості.
    //
    // Шукаємо і в публічній папці, і всередині проєкту: на хостингу без права
    // змінювати document root це різні місця, і файл фізично лежить у проєкті.
    $logo = collect(['images/logo.svg', 'images/logo.webp', 'images/logo.png', 'images/logo.jpg'])
        ->first(fn (string $path): bool => file_exists(public_path($path))
            || file_exists(base_path("public_html/{$path}")));
@endphp

@if ($logo)
    <img src="{{ asset($logo) }}"
         alt="{{ \App\Models\Setting::get('school_name') ?: 'Логотип школи' }}"
         {{ $attributes->merge(['class' => $class]) }}>
@else
    <span {{ $attributes->merge(['class' => 'flex h-9 w-9 items-center justify-center rounded-xl bg-deep-700 text-white']) }}>
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 2v7c0 1.1.9 2 2 2h1a2 2 0 0 0 2-2V2"/>
            <path d="M6 2v20"/>
            <path d="M18 2c-1.7 0-3 2.2-3 5s.7 4 3 4v11"/>
        </svg>
    </span>
@endif

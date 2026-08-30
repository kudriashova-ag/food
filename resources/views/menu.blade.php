@extends('layouts.app')

@section('title', $supplier->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('home') }}"
           class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-deep-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            До постачальників
        </a>

        <div class="mt-3 flex items-center gap-4">
            @if ($supplier->logo_path)
                <img src="{{ Storage::disk('public')->url($supplier->logo_path) }}" alt=""
                     class="h-14 w-14 shrink-0 rounded-xl object-contain ring-1 ring-ink-200">
            @endif

            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ $supplier->name }}</h1>
                <p class="text-sm text-ink-500">Комплекс купується цілком за фіксованою ціною. Choice/Extra підсумовуються окремо.</p>
            </div>
        </div>
    </div>

    @forelse ($days as $day)
        @php
            $deadline = $deadlines[$day->date->toDateString()] ?? null;
            $open = $deadline?->orderingOpen() ?? false;

            // Розгорнутий лише найближчий день, що ще приймає замовлення:
            // решту відкривають дотиком по шапці.
            $expanded = $day->date->toDateString() === $expandedDate;

            $mainSections = $day->sections->where('type', '!==', \App\Enums\MenuSectionType::Extra);
            $extraSections = $day->sections->where('type', \App\Enums\MenuSectionType::Extra);

            // Стартова сума: 1 порція кожного комплексу (за його фіксованою ціною).
            // Далі значення перераховує JS при кожній зміні вибору.
            $defaultTotal = $day->sections
                ->where('type', \App\Enums\MenuSectionType::Complex)
                ->sum(fn ($section) => (float) $section->price);

            $isToday = $day->date->isToday();

            // Уже доданий день не пропонуємо додати вдруге — правити його склад
            // потрібно в кошику, інакше кількості мовчки складалися б.
            $inCart = in_array($day->date->toDateString(), $datesInCart, true);
        @endphp

        <details @class([
                    'group card mb-3 overflow-hidden',
                    'opacity-75' => ! $open,
                 ]) @if ($expanded) open @endif>
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3.5
                            select-none transition hover:bg-ink-50 [&::-webkit-details-marker]:hidden">
                <span class="flex items-center gap-2.5">
                    <svg class="h-4 w-4 shrink-0 text-ink-400 transition-transform group-open:rotate-90"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>

                    <h2 @class([
                            'font-semibold',
                            'text-ink-900' => $open,
                            'text-ink-500' => ! $open,
                        ])>
                        {{ $day->date->translatedFormat('l, d.m') }}
                    </h2>

                    @if ($isToday)
                        <span class="badge bg-brand-100 text-deep-800">сьогодні</span>
                    @endif

                    @if ($inCart)
                        <span class="badge inline-flex items-center gap-1 bg-deep-700 text-white" data-day-badge>
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                            у кошику
                        </span>
                    @endif
                </span>

                @if ($open)
                    <span class="flex items-center gap-1.5 text-xs text-ink-500">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                        </svg>
                        {{ $deadline->orderLabel() }}
                    </span>
                @else
                    <span class="badge bg-amber-50 text-amber-800">Приймання завершено</span>
                @endif
            </summary>

            <form method="POST" action="{{ route('cart.store-day', [$supplier->slug, $day->date->toDateString()]) }}"
                  class="border-t border-ink-100" {{ $open ? 'data-day-form' : '' }}>
                @csrf

                {{-- Доданий день теж блокуємо: міняти вибір тут уже нема куди,
                     склад правиться в кошику. --}}
                <fieldset @disabled(! $open || $inCart) data-day-fields>
                    <div class="grid gap-5 px-4 py-4 {{ $extraSections->isNotEmpty() && $mainSections->isNotEmpty() ? 'md:grid-cols-2' : '' }}">
                        @if ($mainSections->isNotEmpty())
                            <div class="space-y-5">
                                <div class="text-xs font-semibold uppercase tracking-wider text-deep-700">Комплекс</div>

                                @foreach ($mainSections as $section)
                                    <x-menu-section :section="$section" />
                                @endforeach
                            </div>
                        @endif

                        @if ($extraSections->isNotEmpty())
                            <div class="space-y-3 md:border-l md:border-ink-100 md:pl-5">
                                <div class="text-xs font-semibold uppercase tracking-wider text-ink-400">Додатково</div>

                                {{-- Додаткових страв буває багато — колонка гортається окремо від сторінки. --}}
                                <div class="max-h-96 space-y-5 overflow-y-auto pr-1">
                                    @foreach ($extraSections as $section)
                                        <x-menu-section :section="$section" />
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </fieldset>

                @if ($open)
                    <div class="border-t border-ink-100 bg-ink-50/60 px-4 py-4">
                        {{-- Доданий день показує посилання на кошик замість кнопки:
                             склад правиться там. JS перемикає ці блоки після додавання. --}}
                        <div data-day-added @unless ($inCart) hidden @endunless>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <span class="flex items-center gap-2 text-sm font-medium text-deep-800">
                                    <svg class="h-5 w-5 shrink-0 text-deep-700" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true">
                                        <circle cx="12" cy="12" r="10"/><path d="M20 6 9 17l-5-5"/>
                                    </svg>
                                    Цей день уже в кошику
                                </span>

                                <a href="{{ route('cart') }}" class="btn-accent">
                                    Змінити в кошику
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="m9 18 6-6-6-6"/>
                                    </svg>
                                </a>
                            </div>
                        </div>

                        <div data-day-pending @if ($inCart) hidden @endif>
                            <div class="mb-3 flex items-baseline justify-between gap-3">
                                <span class="text-sm text-ink-500">Разом за день</span>
                                <span class="text-xl font-bold tabular-nums" data-day-total>
                                    {{ number_format($defaultTotal, 2, ',', ' ') }} грн
                                </span>
                            </div>

                            <button type="submit" class="btn-primary w-full">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12h14M12 5v14"/>
                                </svg>
                                Додати цей день у кошик
                            </button>
                        </div>
                    </div>
                @endif
            </form>
        </details>
    @empty
        <div class="card flex flex-col items-center gap-3 p-10 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100">
                <svg class="h-7 w-7 text-ink-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>
                </svg>
            </span>
            <p class="font-medium">Меню ще не опубліковане</p>
            <p class="text-sm text-ink-500">Загляньте пізніше — постачальник саме його готує.</p>
        </div>
    @endforelse
@endsection

@section('sticky-bar')
    <x-cart-bar />
@endsection

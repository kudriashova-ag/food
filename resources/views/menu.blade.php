@extends('layouts.app')

@section('title', $supplier->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('home') }}" class="text-sm text-zinc-500 hover:underline">← До постачальників</a>
        <h1 class="mt-2 text-xl font-semibold">{{ $supplier->name }}</h1>
        <p class="text-sm text-zinc-500">Ціна дня — це сума обраних страв.</p>
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

            // Стартова сума: комплекс приходить із відміченими стравами.
            // Далі значення перераховує JS при кожній зміні вибору.
            $defaultTotal = $day->sections
                ->where('type', \App\Enums\MenuSectionType::Complex)
                ->flatMap(fn ($section) => $section->sectionDishes)
                ->sum(fn ($sectionDish) => (float) $sectionDish->dish->price);
        @endphp

        <details @class([
                    'group mb-3 overflow-hidden rounded-xl border bg-white',
                    'border-zinc-200' => $open,
                    'border-zinc-200/70' => ! $open,
                 ]) @if ($expanded) open @endif>
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3 select-none hover:bg-zinc-50 [&::-webkit-details-marker]:hidden">
                <span class="flex items-center gap-2">
                    <span class="text-zinc-400 transition-transform group-open:rotate-90">›</span>
                    <h2 class="font-semibold {{ $open ? '' : 'text-zinc-500' }}">
                        {{ $day->date->translatedFormat('l, d.m') }}
                    </h2>
                </span>

                @if ($open)
                    <span class="text-xs text-zinc-500">{{ $deadline->orderLabel() }}</span>
                @else
                    <span class="text-xs font-medium text-amber-700">Приймання замовлень завершено</span>
                @endif
            </summary>

            <form method="POST" action="{{ route('cart.store-day', [$supplier->slug, $day->date->toDateString()]) }}"
                  class="border-t border-zinc-100" {{ $open ? 'data-day-form' : '' }}>
                @csrf

                <fieldset @disabled(! $open) class="{{ $open ? '' : 'opacity-50' }}">
                    <div class="grid gap-4 px-4 py-3 {{ $extraSections->isNotEmpty() && $mainSections->isNotEmpty() ? 'md:grid-cols-2' : '' }}">
                        @if ($mainSections->isNotEmpty())
                            <div class="space-y-4">
                                <div class="text-xs font-medium tracking-wide text-zinc-400 uppercase">Основні страви</div>

                                @foreach ($mainSections as $section)
                                    <x-menu-section :section="$section" />
                                @endforeach
                            </div>
                        @endif

                        @if ($extraSections->isNotEmpty())
                            <div class="space-y-2 md:border-l md:border-zinc-100 md:pl-4">
                                <div class="text-xs font-medium tracking-wide text-zinc-400 uppercase">Додатково</div>

                                {{-- Додаткових страв буває багато — колонка гортається окремо від сторінки. --}}
                                <div class="max-h-96 space-y-4 overflow-y-auto pr-1">
                                    @foreach ($extraSections as $section)
                                        <x-menu-section :section="$section" />
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </fieldset>

                @if ($open)
                    <div class="border-t border-zinc-100 px-4 py-3">
                        <div class="mb-3 flex items-baseline justify-between gap-3">
                            <span class="text-sm text-zinc-500">Разом за день</span>
                            <span class="text-lg font-semibold" data-day-total>
                                {{ number_format($defaultTotal, 2, ',', ' ') }} грн
                            </span>
                        </div>

                        <button type="submit"
                                class="w-full rounded-lg bg-zinc-900 px-4 py-3 text-sm font-medium text-white hover:bg-zinc-800">
                            Додати цей день у кошик
                        </button>
                    </div>
                @endif
            </form>
        </details>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">
            Меню на найближчі дні ще не опубліковане.
        </div>
    @endforelse
@endsection

@section('sticky-bar')
    <x-cart-bar />
@endsection

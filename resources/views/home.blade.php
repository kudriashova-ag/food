@extends('layouts.app')

@section('title', 'Постачальники')

@section('main-padding', 'p-0')

@section('content')
    @php
        // Два постачальники — половини екрана. Три — третини. Більше — сітка карток,
        // щоб колонки не стали нечитабельно вузькими.
        $columns = match (true) {
            $suppliers->count() === 1 => 'md:grid-cols-1',
            $suppliers->count() === 2 => 'md:grid-cols-2',
            $suppliers->count() === 3 => 'md:grid-cols-3',
            default => 'md:grid-cols-2 lg:grid-cols-3',
        };

        // Панелі чергують кольори: салатовий і темно-зелений.
        $palettes = [
            [
                'panel' => 'bg-brand-500 text-deep-900',
                'muted' => 'text-deep-900/65',
                'chip' => 'bg-deep-900/10 text-deep-900',
                'btn' => 'bg-deep-700 text-white hover:bg-deep-600',
                'photo' => 'rgba(0,0,0,.08)',
            ],
            [
                'panel' => 'bg-deep-700 text-white',
                'muted' => 'text-white/70',
                'chip' => 'bg-white/15 text-white',
                'btn' => 'bg-brand-500 text-deep-900 hover:bg-brand-400',
                'photo' => 'rgba(255,255,255,.1)',
            ],
        ];

        $weekStart = now()->startOfWeek();
    @endphp

    <section class="px-4 py-10 text-center  sm:py-6">
        <div class="mx-auto max-w-4xl">
            <div class="mb-3 text-xs font-bold uppercase tracking-[0.22em] text-brand-500">
                {{ $weekStart->translatedFormat('d') }}–{{ $weekStart->addDays(4)->translatedFormat('d F') }}
            </div>

            <h1 class="text-4xl font-black leading-none tracking-tight sm:text-5xl">Що замовити</h1>

            <p class="mx-auto mt-4 max-w-2-xl text-ink-500/65">
                Можна замовляти в кількох постачальників на один день — кошик спільний.
            </p>
        </div>
    </section>

    @if ($suppliers->isEmpty())
        <div class="px-4 py-16 text-center">
            <p class="font-medium">Постачальників поки немає</p>
            <p class="mt-1 text-sm text-ink-500">Зверніться до адміністрації школи.</p>
        </div>
    @else
        {{-- Скруглення задає сама сітка через overflow-hidden: крайні панелі
             обрізаються по її кутах. Так воно лишається правильним і на телефоні,
             коли панелі переносяться в стовпчик, і за будь-якої їх кількості. --}}
        <div class="mx-4 grid gap-px overflow-hidden rounded-[2rem] bg-ink-200 {{ $columns }} sm:mx-6">
            @foreach ($suppliers as $index => $supplier)
                @php $p = $palettes[$index % 2]; @endphp

                <div class="flex flex-col gap-5 p-8 sm:p-10 {{ $p['panel'] }}">
                    <h2 class="text-3xl font-black leading-none tracking-tight sm:text-4xl">
                        {{ $supplier->name }}
                    </h2>

                    @if ($supplier->description)
                        <p class="font-medium text-sm {{ $p['muted'] }}">{{ $supplier->description }}</p>
                    @endif

                    @if ($supplier->logo_path)
                        {{-- object-contain: логотип має бути видно цілком, а не обрізаним.
                             Тло приховує поля, які лишаються навколо. --}}
                        <img src="{{ Storage::disk('public')->url($supplier->logo_path) }}" alt=""
                             loading="lazy" class="h-40 w-full rounded-xl bg-white/60 object-contain p-3">
                    @else
                        <div class="flex h-40 items-center justify-center rounded-xl"
                             style="background: repeating-linear-gradient(135deg, {{ $p['photo'] }}, {{ $p['photo'] }} 9px, transparent 9px, transparent 18px)">
                            <span class="text-6xl font-black opacity-25">{{ mb_substr($supplier->name, 0, 1) }}</span>
                        </div>
                    @endif

                    <a href="{{ route('menu', $supplier->slug) }}"
                       class="mt-auto inline-flex items-center justify-center gap-2 rounded-xl px-5 py-4
                              text-base font-bold shadow-sm transition active:scale-[0.99] {{ $p['btn'] }}">
                        Обрати страви по днях
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    <div class="px-4 py-6 text-center text-sm text-ink-500/70">
        Меню веде постачальник · Приймання замовлень обмежене дедлайном
    </div>
@endsection

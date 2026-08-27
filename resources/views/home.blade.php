@extends('layouts.app')

@section('title', 'Постачальники')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Куди замовляємо</h1>
        <p class="mt-2 max-w-2xl text-ink-500">
            Можна замовляти в кількох постачальників на один день — кошик спільний.
            @guest
                <span class="text-ink-400">Меню відкрите без входу: логін знадобиться лише на оформленні.</span>
            @endguest
        </p>
    </div>

    @forelse ($suppliers as $supplier)
        <a href="{{ route('menu', $supplier->slug) }}"
           class="card card-hover group mb-3 flex items-center gap-4 p-4">
            @if ($supplier->logo_path)
                <img src="{{ Storage::disk('public')->url($supplier->logo_path) }}"
                     alt="" loading="lazy"
                     class="h-16 w-16 shrink-0 rounded-xl object-cover ring-1 ring-ink-200">
            @else
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-2xl font-bold text-deep-800">
                    {{ mb_substr($supplier->name, 0, 1) }}
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <div class="text-lg font-semibold group-hover:text-deep-700">{{ $supplier->name }}</div>

                @if ($supplier->description)
                    <div class="mt-0.5 line-clamp-2 text-sm text-ink-500">{{ $supplier->description }}</div>
                @endif
            </div>

            <svg class="h-5 w-5 shrink-0 text-ink-300 transition group-hover:translate-x-0.5 group-hover:text-deep-500"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
    @empty
        <div class="card flex flex-col items-center gap-3 p-10 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100">
                <svg class="h-7 w-7 text-ink-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 2v7c0 1.1.9 2 2 2h1a2 2 0 0 0 2-2V2"/><path d="M6 2v20"/>
                    <path d="M18 2c-1.7 0-3 2.2-3 5s.7 4 3 4v11"/>
                </svg>
            </span>
            <p class="font-medium">Постачальників поки немає</p>
            <p class="text-sm text-ink-500">Зверніться до адміністрації школи.</p>
        </div>
    @endforelse
@endsection

@section('sticky-bar')
    <x-cart-bar />
@endsection

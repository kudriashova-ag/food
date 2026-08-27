@extends('layouts.app')

@section('title', 'Постачальники')

@section('content')
    <h1 class="mb-1 text-xl font-semibold">Куди замовляємо</h1>
    <p class="mb-6 text-sm text-zinc-500">
        Можна замовляти в кількох постачальників на один день — кошик спільний.
        @guest
            Меню й кошик відкриті без входу — логін учня знадобиться лише на оформленні.
        @endguest
    </p>

    @forelse ($suppliers as $supplier)
        <a href="{{ route('menu', $supplier->slug) }}"
           class="mb-3 flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-400">
            @if ($supplier->logo_path)
                <img src="{{ Storage::disk('public')->url($supplier->logo_path) }}"
                     alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover">
            @else
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg font-semibold text-zinc-400">
                    {{ mb_substr($supplier->name, 0, 1) }}
                </div>
            @endif

            <div class="min-w-0">
                <div class="font-medium">{{ $supplier->name }}</div>
                @if ($supplier->description)
                    <div class="truncate text-sm text-zinc-500">{{ $supplier->description }}</div>
                @endif
            </div>
        </a>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">
            Постачальників поки немає. Зверніться до адміністрації школи.
        </div>
    @endforelse
@endsection

@section('sticky-bar')
    <x-cart-bar />
@endsection

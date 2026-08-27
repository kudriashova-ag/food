@extends('layouts.app')

@section('title', 'Кошик')

@section('content')
    <h1 class="mb-6 text-xl font-semibold">Кошик</h1>

    @if ($expired->isNotEmpty())
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="mb-1 font-medium">Термін замовлення минув, поки кошик чекав</p>
            <ul class="list-inside list-disc">
                @foreach ($expired as $item)
                    <li>{{ $item->dish->name }} — {{ $item->service_date->translatedFormat('d.m') }}</li>
                @endforeach
            </ul>
            <p class="mt-2">Приберіть ці позиції, щоб оформити решту.</p>
        </div>
    @endif

    @forelse ($groups as $group)
        <section class="mb-4 overflow-hidden rounded-xl border border-zinc-200 bg-white">
            <header class="border-b border-zinc-100 px-4 py-3">
                <h2 class="font-semibold">{{ $group['supplier']->name }}</h2>
            </header>

            @foreach ($group['dates'] as $date)
                <div class="border-b border-zinc-100 px-4 py-3 last:border-0">
                    <div class="mb-2 flex items-baseline justify-between gap-2">
                        <h3 class="text-sm font-medium">{{ $date['date']->translatedFormat('l, d.m') }}</h3>
                        <span class="text-xs {{ $date['deadline']->orderingOpen() ? 'text-zinc-500' : 'text-red-600' }}">
                            {{ $date['deadline']->orderingOpen() ? $date['deadline']->orderLabel() : 'Приймання завершено' }}
                        </span>
                    </div>

                    <ul class="space-y-2">
                        @foreach ($date['items'] as $item)
                            <li class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm">{{ $item->dish->name }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ number_format((float) $item->dish->price, 2, ',', ' ') }} грн
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('cart.update-item', $item) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select name="quantity" onchange="this.form.submit()"
                                            class="rounded border border-zinc-300 px-2 py-1 text-sm">
                                        @for ($n = 1; $n <= 10; $n++)
                                            <option value="{{ $n }}" @selected($n === $item->quantity)>{{ $n }}</option>
                                        @endfor
                                    </select>
                                </form>

                                <div class="w-20 shrink-0 text-right text-sm font-medium">
                                    {{ number_format($item->subtotal(), 2, ',', ' ') }}
                                </div>

                                <form method="POST" action="{{ route('cart.destroy-item', $item) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1 text-zinc-400 hover:text-red-600" title="Прибрати">
                                        ✕
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-2 text-right text-sm text-zinc-500">
                        Разом за день: {{ number_format($date['total'], 2, ',', ' ') }} грн
                    </div>
                </div>
            @endforeach
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">
            Кошик порожній.
            <a href="{{ route('home') }}" class="text-zinc-900 underline">Обрати страви</a>
        </div>
    @endforelse

    @if ($groups->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-4">
            <div class="mb-4 flex items-baseline justify-between">
                <span class="font-medium">Усього</span>
                <span class="text-lg font-semibold">{{ number_format($total, 2, ',', ' ') }} грн</span>
            </div>

            @if (auth()->user()?->isStudent())
                <form method="POST" action="{{ route('orders.store') }}">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-lg bg-zinc-900 px-4 py-3 text-base font-medium text-white hover:bg-zinc-800">
                        Підтвердити замовлення
                    </button>
                </form>

                <p class="mt-3 text-center text-xs text-zinc-500">
                    Оплата поза сайтом. Після підтвердження ви отримаєте номер замовлення.
                </p>
            @elseif (auth()->check())
                <p class="rounded-lg bg-zinc-100 px-4 py-3 text-center text-sm text-zinc-600">
                    Замовлення оформлюється з акаунта учня.
                </p>
            @else
                {{-- Єдине місце, де потрібен вхід: страви обирають без логіна. --}}
                <a href="{{ route('login') }}"
                   class="block w-full rounded-lg bg-zinc-900 px-4 py-3 text-center text-base font-medium text-white hover:bg-zinc-800">
                    Увійти й оформити замовлення
                </a>

                <p class="mt-3 text-center text-xs text-zinc-500">
                    Логін і пароль учня видає школа. Кошик збережеться — після входу ви повернетесь сюди.
                </p>
            @endif
        </div>
    @endif
@endsection

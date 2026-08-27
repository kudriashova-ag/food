@extends('layouts.app')

@section('title', 'Кошик')

@section('content')
    <h1 class="mb-6 text-2xl font-bold tracking-tight sm:text-3xl">Кошик</h1>

    @if ($expired->isNotEmpty())
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3.5 text-sm text-amber-900">
            <p class="mb-1.5 flex items-center gap-2 font-semibold">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                </svg>
                Термін замовлення минув, поки кошик чекав
            </p>
            <ul class="ml-6 list-disc space-y-0.5">
                @foreach ($expired as $item)
                    <li>{{ $item->dish->name }} — {{ $item->service_date->translatedFormat('d.m') }}</li>
                @endforeach
            </ul>
            <p class="mt-2">Приберіть ці позиції, щоб оформити решту.</p>
        </div>
    @endif

    @forelse ($groups as $group)
        <section class="card mb-4 overflow-hidden">
            <header class="flex items-center gap-2.5 border-b border-ink-100 bg-ink-50/60 px-4 py-3">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-100 text-sm font-bold text-deep-700">
                    {{ mb_substr($group['supplier']->name, 0, 1) }}
                </span>
                <h2 class="font-semibold">{{ $group['supplier']->name }}</h2>
            </header>

            @foreach ($group['dates'] as $date)
                <div class="border-b border-ink-100 px-4 py-3.5 last:border-0">
                    <div class="mb-2.5 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold">{{ $date['date']->translatedFormat('l, d.m') }}</h3>

                        <span @class([
                                  'text-xs',
                                  'text-ink-500' => $date['deadline']->orderingOpen(),
                                  'font-medium text-red-600' => ! $date['deadline']->orderingOpen(),
                              ])>
                            {{ $date['deadline']->orderingOpen()
                                ? $date['deadline']->orderLabel()
                                : 'Приймання завершено' }}
                        </span>
                    </div>

                    <ul class="space-y-2.5">
                        @foreach ($date['items'] as $item)
                            <li class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">{{ $item->dish->name }}</div>
                                    <div class="text-xs text-ink-500 tabular-nums">
                                        {{ number_format((float) $item->dish->price, 2, ',', ' ') }} грн
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('cart.update-item', $item) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select name="quantity" onchange="this.form.submit()"
                                            class="w-16 rounded-lg border border-ink-300 bg-white px-2 py-1.5 text-center text-sm font-semibold
                                                   focus:border-deep-500 focus:outline-none focus:ring-2 focus:ring-deep-100">
                                        @for ($n = 1; $n <= 10; $n++)
                                            <option value="{{ $n }}" @selected($n === $item->quantity)>{{ $n }}</option>
                                        @endfor
                                    </select>
                                </form>

                                <div class="w-20 shrink-0 text-right text-sm font-semibold tabular-nums">
                                    {{ number_format($item->subtotal(), 2, ',', ' ') }}
                                </div>

                                <form method="POST" action="{{ route('cart.destroy-item', $item) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Прибрати"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-400 transition
                                                   hover:bg-red-50 hover:text-red-600">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M18 6 6 18M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-2.5 text-right text-sm text-ink-500">
                        Разом за день:
                        <span class="font-semibold text-ink-900 tabular-nums">
                            {{ number_format($date['total'], 2, ',', ' ') }} грн
                        </span>
                    </div>
                </div>
            @endforeach
        </section>
    @empty
        <div class="card flex flex-col items-center gap-3 p-10 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100">
                <svg class="h-7 w-7 text-ink-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>
                    <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
                </svg>
            </span>
            <p class="font-medium">Кошик порожній</p>
            <a href="{{ route('home') }}" class="btn-secondary mt-1">Обрати страви</a>
        </div>
    @endforelse

    @if ($groups->isNotEmpty())
        <div class="card p-5">
            <div class="mb-4 flex items-baseline justify-between">
                <span class="font-semibold">Усього</span>
                <span class="text-2xl font-bold tabular-nums">
                    {{ number_format($total, 2, ',', ' ') }} <span class="text-base text-ink-500">грн</span>
                </span>
            </div>

            @if (auth()->user()?->isStudent())
                <form method="POST" action="{{ route('orders.store') }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full">Підтвердити замовлення</button>
                </form>

                <p class="mt-3 text-center text-xs text-ink-500">
                    Оплата поза сайтом. Після підтвердження ви отримаєте номер замовлення.
                </p>
            @elseif (auth()->check())
                <p class="rounded-xl bg-ink-100 px-4 py-3 text-center text-sm text-ink-600">
                    Замовлення оформлюється з акаунта учня.
                </p>
            @else
                {{-- Єдине місце, де потрібен вхід: страви обирають без логіна. --}}
                <a href="{{ route('login') }}" class="btn-primary w-full">
                    Увійти й оформити замовлення
                </a>

                <p class="mt-3 text-center text-xs text-ink-500">
                    Логін і пароль учня видає школа. Кошик збережеться — після входу ви повернетесь сюди.
                </p>
            @endif
        </div>
    @endif
@endsection

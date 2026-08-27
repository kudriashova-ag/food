@extends('layouts.app')

@section('title', 'Мої замовлення')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Мої замовлення</h1>
        <a href="{{ route('home') }}" class="btn-secondary py-2 text-sm">Замовити ще</a>
    </div>

    <div class="card mb-4 flex items-center justify-between gap-2 px-2 py-1.5 text-sm">
        <a href="{{ route('orders.index', ['week' => $weekStart->subWeek()->toDateString()]) }}"
           class="flex items-center gap-1 rounded-lg px-3 py-2 font-medium text-ink-500 transition hover:bg-ink-100 hover:text-ink-900">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            <span class="hidden sm:inline">Попередній</span>
        </a>

        <span class="font-semibold">
            {{ $weekStart->translatedFormat('d.m') }} – {{ $weekEnd->translatedFormat('d.m.Y') }}
        </span>

        <a href="{{ route('orders.index', ['week' => $weekStart->addWeek()->toDateString()]) }}"
           class="flex items-center gap-1 rounded-lg px-3 py-2 font-medium text-ink-500 transition hover:bg-ink-100 hover:text-ink-900">
            <span class="hidden sm:inline">Наступний</span>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>
    </div>

    <form method="POST" action="{{ route('orders.repeat-week') }}" class="mb-5">
        @csrf
        <input type="hidden" name="source" value="{{ $weekStart->toDateString() }}">
        <button type="submit" class="btn-secondary w-full text-sm">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>
            </svg>
            Повторити цей тиждень на {{ $weekStart->addWeek()->translatedFormat('d.m') }}–{{ $weekEnd->addWeek()->translatedFormat('d.m') }}
        </button>
    </form>

    @foreach ($days as $day)
        @php $hasOrders = $day['suppliers']->isNotEmpty(); @endphp

        <section @class(['card mb-3 overflow-hidden', 'opacity-70' => ! $hasOrders])>
            <header class="flex items-center gap-2 border-b border-ink-100 bg-ink-50/60 px-4 py-2.5">
                <h2 class="text-sm font-semibold">{{ $day['date']->translatedFormat('l, d.m') }}</h2>

                @if ($day['date']->isToday())
                    <span class="badge bg-brand-100 text-brand-800">сьогодні</span>
                @endif
            </header>

            @forelse ($day['suppliers'] as $group)
                <div class="border-b border-ink-100 px-4 py-3.5 last:border-0">
                    <div class="mb-2.5 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold">{{ $group['supplier']->name }}</h3>

                        @if ($group['deadline']->cancellationOpen())
                            <form method="POST"
                                  action="{{ route('orders.cancel-day', [$group['supplier']->slug, $day['date']->toDateString()]) }}"
                                  onsubmit="return confirm('Скасувати всі страви цього постачальника на цей день?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="text-xs font-medium text-ink-500 transition hover:text-red-600">
                                    Скасувати день · {{ $group['deadline']->cancelLabel() }}
                                </button>
                            </form>
                        @else
                            <span class="text-xs text-ink-400">Скасування недоступне</span>
                        @endif
                    </div>

                    <ul class="space-y-2">
                        @foreach ($group['lines'] as $line)
                            <li class="flex items-center gap-3 text-sm">
                                <div class="min-w-0 flex-1">
                                    <span @class(['text-ink-400 line-through' => $line->isCancelled()])>
                                        {{ $line->dish_name }}
                                        @if ($line->quantity > 1)
                                            <span class="font-semibold">× {{ $line->quantity }}</span>
                                        @endif
                                    </span>

                                    @if ($line->isCancelled())
                                        <div class="text-xs text-ink-400">
                                            Скасовано {{ $line->cancelled_at?->translatedFormat('d.m о H:i') }}
                                            @if ($line->cancel_reason)
                                                · {{ $line->cancel_reason }}
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <span @class([
                                          'shrink-0 tabular-nums',
                                          'text-ink-400' => $line->isCancelled(),
                                          'font-medium' => ! $line->isCancelled(),
                                      ])>
                                    {{ number_format($line->subtotal(), 2, ',', ' ') }}
                                </span>

                                @if (! $line->isCancelled() && $group['deadline']->cancellationOpen())
                                    <form method="POST" action="{{ route('orders.cancel-line', $line) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" title="Скасувати"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg text-ink-400 transition
                                                       hover:bg-red-50 hover:text-red-600">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M18 6 6 18M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </form>
                                @else
                                    <span class="w-7"></span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-2.5 text-right">
                        <span class="text-sm font-semibold tabular-nums">
                            {{ number_format($group['total'], 2, ',', ' ') }} грн
                        </span>
                    </div>
                </div>
            @empty
                <div class="px-4 py-3 text-sm text-ink-400">Харчування не замовлено</div>
            @endforelse
        </section>
    @endforeach
@endsection

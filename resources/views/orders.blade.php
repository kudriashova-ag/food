@extends('layouts.app')

@section('title', 'Мої замовлення')

@section('content')
    <div class="mb-6 flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Мої замовлення</h1>
        <a href="{{ route('home') }}" class="text-sm text-zinc-500 hover:underline">Замовити ще</a>
    </div>

    <form method="POST" action="{{ route('orders.repeat-week') }}" class="mb-4">
        @csrf
        <input type="hidden" name="source" value="{{ $weekStart->toDateString() }}">
        <button type="submit"
                class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-medium hover:border-zinc-900">
            Повторити цей тиждень на {{ $weekStart->addWeek()->translatedFormat('d.m') }}–{{ $weekEnd->addWeek()->translatedFormat('d.m') }}
        </button>
    </form>

    <div class="mb-4 flex items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
        <a href="{{ route('orders.index', ['week' => $weekStart->subWeek()->toDateString()]) }}"
           class="px-2 py-1 text-zinc-500 hover:text-zinc-900">← Попередній</a>

        <span class="font-medium">
            {{ $weekStart->translatedFormat('d.m') }} – {{ $weekEnd->translatedFormat('d.m.Y') }}
        </span>

        <a href="{{ route('orders.index', ['week' => $weekStart->addWeek()->toDateString()]) }}"
           class="px-2 py-1 text-zinc-500 hover:text-zinc-900">Наступний →</a>
    </div>

    @foreach ($days as $day)
        <section class="mb-3 overflow-hidden rounded-xl border border-zinc-200 bg-white">
            <header class="border-b border-zinc-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold">{{ $day['date']->translatedFormat('l, d.m') }}</h2>
            </header>

            @forelse ($day['suppliers'] as $group)
                <div class="border-b border-zinc-100 px-4 py-3 last:border-0">
                    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-medium">{{ $group['supplier']->name }}</h3>

                        @if ($group['deadline']->cancellationOpen())
                            <form method="POST"
                                  action="{{ route('orders.cancel-day', [$group['supplier']->slug, $day['date']->toDateString()]) }}"
                                  onsubmit="return confirm('Скасувати всі страви цього постачальника на цей день?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs text-zinc-500 underline-offset-2 hover:text-red-600 hover:underline">
                                    Скасувати день · {{ $group['deadline']->cancelLabel() }}
                                </button>
                            </form>
                        @else
                            <span class="text-xs text-zinc-400">Скасування недоступне</span>
                        @endif
                    </div>

                    <ul class="space-y-1.5">
                        @foreach ($group['lines'] as $line)
                            <li class="flex items-center gap-3 text-sm">
                                <div class="min-w-0 flex-1">
                                    <span class="{{ $line->isCancelled() ? 'text-zinc-400 line-through' : '' }}">
                                        {{ $line->dish_name }}
                                        @if ($line->quantity > 1)
                                            × {{ $line->quantity }}
                                        @endif
                                    </span>

                                    @if ($line->isCancelled())
                                        <span class="ml-1 text-xs text-zinc-400">
                                            Скасовано {{ $line->cancelled_at?->translatedFormat('d.m о H:i') }}
                                            @if ($line->cancel_reason)
                                                · {{ $line->cancel_reason }}
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                <span class="{{ $line->isCancelled() ? 'text-zinc-400' : '' }}">
                                    {{ number_format($line->subtotal(), 2, ',', ' ') }}
                                </span>

                                @if (! $line->isCancelled() && $group['deadline']->cancellationOpen())
                                    <form method="POST" action="{{ route('orders.cancel-line', $line) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1 text-zinc-400 hover:text-red-600" title="Скасувати">
                                            ✕
                                        </button>
                                    </form>
                                @else
                                    <span class="w-6"></span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-2 text-right text-sm font-medium">
                        {{ number_format($group['total'], 2, ',', ' ') }} грн
                    </div>
                </div>
            @empty
                <div class="px-4 py-3 text-sm text-zinc-400">Харчування не замовлено</div>
            @endforelse
        </section>
    @endforeach
@endsection

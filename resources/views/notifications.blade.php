@extends('layouts.app')

@section('title', 'Налаштування')

@section('content')
    <h1 class="mb-6 text-xl font-semibold">Налаштування</h1>

    @if (session('telegram_link'))
        <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-4">
            <p class="mb-3 text-sm text-sky-900">
                Посилання дійсне 15 хвилин. Відкрийте його на телефоні з Telegram і натисніть «Старт».
            </p>
            <a href="{{ session('telegram_link') }}" target="_blank" rel="noopener"
               class="inline-block rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-700">
                Відкрити Telegram
            </a>
            <p class="mt-3 break-all text-xs text-sky-800">{{ session('telegram_link') }}</p>
        </div>
    @endif

    <section class="mb-4 rounded-xl border border-zinc-200 bg-white p-4">
        <h2 class="mb-1 font-medium">E-mail для сповіщень</h2>
        <p class="mb-3 text-sm text-zinc-500">
            На нього приходять підтвердження замовлень і нагадування. Можна лишити порожнім.
        </p>

        <form method="POST" action="{{ route('settings.email') }}" class="flex flex-wrap items-start gap-2">
            @csrf
            @method('PATCH')

            <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}"
                   placeholder="mama@example.com"
                   class="min-w-0 flex-1 rounded-lg border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-900 focus:outline-none">

            <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-800">
                Зберегти
            </button>
        </form>

        @error('email')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </section>

    <section class="mb-4 rounded-xl border border-zinc-200 bg-white p-4">
        <h2 class="mb-1 font-medium">Telegram</h2>
        <p class="mb-3 text-sm text-zinc-500">
            Можна підключити кілька — наприклад, мамі й татові окремо.
        </p>

        @forelse ($links as $link)
            <div class="mb-2 flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2">
                <div class="min-w-0 text-sm">
                    <div class="truncate">
                        {{ $link->username ? '@'.$link->username : 'Чат '.$link->chat_id }}
                    </div>
                    <div class="text-xs {{ $link->is_active ? 'text-zinc-500' : 'text-amber-700' }}">
                        {{ $link->is_active
                            ? 'Підключено '.$link->linked_at?->translatedFormat('d.m.Y')
                            : 'Неактивний — бота заблоковано' }}
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.telegram.disconnect', $link) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-sm text-zinc-500 hover:text-red-600">Відключити</button>
                </form>
            </div>
        @empty
            <p class="mb-3 text-sm text-zinc-500">Поки не підключено.</p>
        @endforelse

        @if ($telegramAvailable)
            <form method="POST" action="{{ route('settings.telegram.connect') }}" class="mt-3">
                @csrf
                <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-medium hover:border-zinc-900">
                    Підключити Telegram
                </button>
            </form>
        @else
            <p class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-500">
                Бот ще не налаштований школою.
            </p>
        @endif
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-4">
        <h2 class="mb-3 font-medium">Зміна пароля</h2>

        <form method="POST" action="{{ route('settings.password') }}" class="space-y-3">
            @csrf
            @method('PATCH')

            <div>
                <label class="mb-1 block text-sm">Поточний пароль</label>
                <input type="password" name="current_password" autocomplete="current-password"
                       class="w-full rounded-lg border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-900 focus:outline-none">
                @error('current_password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm">Новий пароль</label>
                <input type="password" name="password" autocomplete="new-password"
                       class="w-full rounded-lg border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-900 focus:outline-none">
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm">Повторіть новий пароль</label>
                <input type="password" name="password_confirmation" autocomplete="new-password"
                       class="w-full rounded-lg border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-900 focus:outline-none">
            </div>

            <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-800">
                Змінити пароль
            </button>
        </form>
    </section>
@endsection

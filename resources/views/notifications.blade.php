@extends('layouts.app')

@section('title', 'Налаштування')

@section('content')
    <h1 class="mb-6 text-2xl font-bold tracking-tight sm:text-3xl">Налаштування</h1>

    @if (session('telegram_link'))
        <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-4">
            <p class="mb-3 text-sm text-sky-900">
                Посилання дійсне 15 хвилин. Відкрийте його на телефоні з Telegram і натисніть «Старт».
            </p>
            <a href="{{ session('telegram_link') }}" target="_blank" rel="noopener" class="btn-primary">
                Відкрити Telegram
            </a>
            <p class="mt-3 break-all text-xs text-sky-800">{{ session('telegram_link') }}</p>
        </div>
    @endif

    <section class="card mb-4 p-5">
        <h2 class="font-semibold">E-mail для сповіщень</h2>
        <p class="mb-3 mt-1 text-sm text-ink-500">
            На нього приходять підтвердження замовлень і нагадування. Можна лишити порожнім.
        </p>

        <form method="POST" action="{{ route('settings.email') }}" class="flex flex-wrap items-start gap-2">
            @csrf
            @method('PATCH')

            <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}"
                   placeholder="mama@example.com" class="field min-w-0 flex-1">

            <button type="submit" class="btn-primary">Зберегти</button>
        </form>

        @error('email')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </section>

    <section class="card mb-4 p-5">
        <h2 class="font-semibold">Telegram</h2>
        <p class="mb-3 mt-1 text-sm text-ink-500">
            Можна підключити кілька — наприклад, мамі й татові окремо.
        </p>

        @forelse ($links as $link)
            <div class="mb-2 flex items-center justify-between gap-3 rounded-xl border border-ink-200 px-3.5 py-2.5">
                <div class="min-w-0 text-sm">
                    <div class="truncate font-medium">
                        {{ $link->username ? '@'.$link->username : 'Чат '.$link->chat_id }}
                    </div>
                    <div class="text-xs {{ $link->is_active ? 'text-ink-500' : 'text-amber-700' }}">
                        {{ $link->is_active
                            ? 'Підключено '.$link->linked_at?->translatedFormat('d.m.Y')
                            : 'Неактивний — бота заблоковано' }}
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.telegram.disconnect', $link) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-ink-500 transition hover:bg-red-50 hover:text-red-600">
                        Відключити
                    </button>
                </form>
            </div>
        @empty
            <p class="mb-3 text-sm text-ink-500">Поки не підключено.</p>
        @endforelse

        @if ($telegramAvailable)
            <form method="POST" action="{{ route('settings.telegram.connect') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary">Підключити Telegram</button>
            </form>
        @else
            <p class="mt-3 rounded-xl bg-ink-100 px-3.5 py-2.5 text-sm text-ink-600">
                Бот ще не налаштований школою.
            </p>
        @endif
    </section>

    <section class="card p-5">
        <h2 class="mb-4 font-semibold">Зміна пароля</h2>

        <form method="POST" action="{{ route('settings.password') }}" class="space-y-3">
            @csrf
            @method('PATCH')

            <div>
                <label class="mb-1.5 block text-sm font-medium">Поточний пароль</label>
                <input type="password" name="current_password" autocomplete="current-password" class="field">
                @error('current_password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium">Новий пароль</label>
                <input type="password" name="password" autocomplete="new-password" class="field">
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium">Повторіть новий пароль</label>
                <input type="password" name="password_confirmation" autocomplete="new-password" class="field">
            </div>

            <button type="submit" class="btn-primary">Змінити пароль</button>
        </form>
    </section>
@endsection

@extends('layouts.app')

@section('title', 'Обробка персональних даних')

@section('content')
    <div class="mx-auto max-w-xl">
        <h1 class="mb-4 text-xl font-semibold">Обробка персональних даних</h1>

        <div class="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 text-sm leading-relaxed text-zinc-700">
            <p>
                Акаунтом користуються батьки або інші законні представники учня. Для роботи сервісу
                ми обробляємо: прізвище, ім'я та по батькові учня, клас, зміст і дати замовлень,
                а також e-mail для сповіщень, якщо ви його вкажете.
            </p>
            <p>
                Дані використовуються виключно для організації харчування: передачі замовлень
                постачальникам і формування списків для видачі на кухні. Стороннім особам вони
                не передаються.
            </p>
            <p>
                {{ \App\Models\Setting::get('privacy_policy_text', 'Повний текст політики обробки персональних даних надає адміністрація школи.') }}
            </p>
        </div>

        <form method="POST" action="{{ route('consent.store') }}" class="mt-6 space-y-4">
            @csrf

            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="agreed" value="1" class="mt-0.5 h-5 w-5 rounded border-zinc-300">
                <span>
                    Я є законним представником учня та надаю згоду на обробку персональних даних
                    у зазначеному обсязі.
                </span>
            </label>

            @error('agreed')
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <button type="submit"
                    class="w-full rounded-lg bg-zinc-900 px-4 py-3 text-base font-medium text-white hover:bg-zinc-800">
                Продовжити
            </button>
        </form>
    </div>
@endsection

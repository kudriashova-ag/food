@extends('layouts.app')

@section('title', 'Обробка персональних даних')

@section('content')
    <div class="mx-auto max-w-xl py-4">
        <div class="mb-5 text-center">
            <span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>
                </svg>
            </span>

            <h1 class="text-2xl font-bold tracking-tight">Обробка персональних даних</h1>
            <p class="mt-2 text-sm text-ink-500">Одноразова згода — далі система про це не питатиме.</p>
        </div>

        <div class="card space-y-3 p-5 text-sm leading-relaxed text-ink-700">
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

        <form method="POST" action="{{ route('consent.store') }}" class="mt-5 space-y-4">
            @csrf

            <label class="card flex cursor-pointer items-start gap-3 p-4 text-sm transition
                          has-[:checked]:border-brand-400 has-[:checked]:bg-brand-50/50">
                <input type="checkbox" name="agreed" value="1"
                       class="mt-0.5 h-5 w-5 shrink-0 rounded border-ink-300 accent-brand-600">
                <span>
                    Я є законним представником учня та надаю згоду на обробку персональних даних
                    у зазначеному обсязі.
                </span>
            </label>

            @error('agreed')
                <p class="flex items-start gap-2 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    {{ $message }}
                </p>
            @enderror

            <button type="submit" class="btn-primary w-full">Продовжити</button>
        </form>
    </div>
@endsection

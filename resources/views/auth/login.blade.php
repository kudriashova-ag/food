@extends('layouts.app')

@section('title', 'Вхід')

@section('main-padding', 'pb-10')

@section('content')
    <div class="mx-auto max-w-sm py-6 sm:py-12">
        <div class="mb-6 text-center">
            <x-school-logo class="mx-auto mb-5 h-20 w-auto" />

            <h1 class="text-2xl font-bold tracking-tight">Вхід у кабінет</h1>
            <p class="mt-2 text-sm text-ink-500">
                Логін і пароль видає школа. Якщо їх немає — зверніться до класного керівника.
            </p>
        </div>

        <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-5">
            @csrf

            <div>
                <label for="login" class="mb-1.5 block text-sm font-medium">Логін учня</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="field">
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium">Пароль</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="field">
            </div>

            @error('login')
                <p class="flex items-start gap-2 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    {{ $message }}
                </p>
            @enderror

            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-600">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-ink-300 accent-deep-700">
                Запам'ятати мене
            </label>

            <button type="submit" class="btn-primary w-full">Увійти</button>
        </form>

        <p class="mt-5 text-center text-sm text-ink-500">
            Меню можна переглядати й без входу —
            <a href="{{ route('home') }}" class="font-medium text-deep-700 hover:underline">подивитися страви</a>
        </p>
    </div>
@endsection

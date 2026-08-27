@extends('layouts.app')

@section('title', 'Вхід')

@section('content')
    <div class="mx-auto max-w-sm">
        <h1 class="mb-1 text-xl font-semibold">Вхід у кабінет</h1>
        <p class="mb-6 text-sm text-zinc-500">
            Логін і пароль видає школа. Якщо їх немає — зверніться до класного керівника.
        </p>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="login" class="mb-1 block text-sm font-medium">Логін учня</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                       autocomplete="username"
                       class="w-full rounded-lg border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-900 focus:outline-none">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Пароль</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-lg border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-900 focus:outline-none">
            </div>

            @error('login')
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <label class="flex items-center gap-2 text-sm text-zinc-600">
                <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-zinc-300">
                Запам'ятати мене
            </label>

            <button type="submit"
                    class="w-full rounded-lg bg-zinc-900 px-4 py-3 text-base font-medium text-white hover:bg-zinc-800">
                Увійти
            </button>
        </form>
    </div>
@endsection

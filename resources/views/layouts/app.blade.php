<!DOCTYPE html>
<html lang="uk" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-zinc-50 text-zinc-900 antialiased">
    <header class="sticky top-0 z-10 border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('home') }}" class="text-base font-semibold">
                {{ config('app.name') }}
            </a>

            <div class="flex items-center gap-3 text-sm">
                @auth
                    @if (auth()->user()->isStudent() && auth()->user()->student?->hasConsented())
                        <a href="{{ route('orders.index') }}" class="text-zinc-600 underline-offset-2 hover:underline">
                            Мої замовлення
                        </a>
                        <a href="{{ route('settings') }}" class="text-zinc-600 underline-offset-2 hover:underline">
                            Налаштування
                        </a>
                    @endif

                    <span class="hidden text-zinc-500 sm:inline">
                        {{ auth()->user()->student?->full_name ?? auth()->user()->name }}
                    </span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-zinc-500 underline-offset-2 hover:underline">
                            Вийти
                        </button>
                    </form>
                @else
                    {{-- Вхід потрібен на оформленні: меню й кошик відкриті всім. --}}
                    @if (! request()->routeIs('login'))
                        <a href="{{ route('login') }}" class="text-zinc-600 underline-offset-2 hover:underline">
                            Увійти
                        </a>
                    @endif
                @endauth
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6 @yield('main-padding', 'pb-24')">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>

    @yield('sticky-bar')
</body>
</html>

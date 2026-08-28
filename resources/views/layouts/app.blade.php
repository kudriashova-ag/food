@php
    $student = auth()->user()?->student;
    $isStudent = auth()->user()?->isStudent() && $student?->hasConsented();
@endphp
<!DOCTYPE html>
<html lang="uk" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#034431">
    <title>@yield('title', config('app.name'))</title>

    {{-- Порожній favicon.ico лишився від Laravel — беремо логотип школи. --}}
    @if (file_exists(public_path('images/logo.webp')) || file_exists(base_path('public_html/images/logo.webp')))
        <link rel="icon" type="image/webp" href="{{ asset('images/logo.webp') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/logo.webp') }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full flex-col bg-ink-50/70 text-ink-900 antialiased">
    {{-- Декоративні плями на фоні. pointer-events-none, щоб не перехоплювали натискання. --}}
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-16 -top-20 h-80 w-[26rem] -rotate-12 rounded-[2.5rem] bg-brand-100/50"></div>
        <div class="absolute -bottom-16 -right-10 h-72 w-72 rounded-[5rem] bg-deep-100/40"></div>
    </div>

    <header class="sticky top-0 z-20 border-b border-ink-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
            {{-- У логотипі вже є назва школи, тому поруч дублюємо лише призначення сервісу. --}}
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                <x-school-logo class="h-10 w-auto shrink-0 sm:h-11" />

                <span class="hidden border-l border-ink-200 pl-3 text-xs font-bold uppercase leading-tight tracking-[0.14em] text-ink-500 sm:block">
                    Шкільне<br>харчування
                </span>
            </a>

            <nav class="flex items-center gap-2 text-sm">
                {{-- Кошик збирають і без входу, тож він у шапці завжди:
                     інакше гість не бачить, що вже поклав. --}}
                <x-cart-button />

                @auth
                    @if ($isStudent)
                        <a href="{{ route('orders.index') }}"
                           @class([
                               'hidden rounded-lg px-3 py-2 font-medium transition sm:block',
                               'bg-brand-100 text-deep-800' => request()->routeIs('orders.*'),
                               'text-ink-600 hover:bg-ink-100' => ! request()->routeIs('orders.*'),
                           ])>
                            Мої замовлення
                        </a>

                        <a href="{{ route('settings') }}"
                           @class([
                               'hidden rounded-lg px-3 py-2 font-medium transition sm:block',
                               'bg-brand-100 text-deep-800' => request()->routeIs('settings*'),
                               'text-ink-600 hover:bg-ink-100' => ! request()->routeIs('settings*'),
                           ])>
                            Налаштування
                        </a>
                    @endif

                    <div class="flex items-center gap-2 border-l border-ink-200 pl-2 sm:pl-3">
                        <span class="hidden max-w-[10rem] truncate text-ink-500 lg:inline">
                            {{ $student?->full_name ?? auth()->user()->name }}
                        </span>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg px-3 py-2 font-medium text-ink-600 transition hover:bg-ink-100">
                                Вийти
                            </button>
                        </form>
                    </div>
                @else
                    {{-- Вхід потрібен на оформленні: меню й кошик відкриті всім. --}}
                    @if (! request()->routeIs('login'))
                        <a href="{{ route('login') }}"
                           class="rounded-lg bg-deep-700 px-4 py-2 font-semibold text-white transition hover:bg-deep-600">
                            Увійти
                        </a>
                    @endif
                @endauth
            </nav>
        </div>

        {{-- На телефоні навігація не влазить у шапку — виносимо окремим рядком. --}}
        @auth
            @if ($isStudent)
                <nav class="flex border-t border-ink-100 sm:hidden">
                    <a href="{{ route('home') }}"
                       @class([
                           'flex-1 py-2.5 text-center text-sm font-medium transition',
                           'text-deep-700' => request()->routeIs('home') || request()->routeIs('menu'),
                           'text-ink-500' => ! (request()->routeIs('home') || request()->routeIs('menu')),
                       ])>
                        Меню
                    </a>
                    <a href="{{ route('orders.index') }}"
                       @class([
                           'flex-1 py-2.5 text-center text-sm font-medium transition',
                           'text-deep-700' => request()->routeIs('orders.*'),
                           'text-ink-500' => ! request()->routeIs('orders.*'),
                       ])>
                        Замовлення
                    </a>
                    <a href="{{ route('settings') }}"
                       @class([
                           'flex-1 py-2.5 text-center text-sm font-medium transition',
                           'text-deep-700' => request()->routeIs('settings*'),
                           'text-ink-500' => ! request()->routeIs('settings*'),
                       ])>
                        Налаштування
                    </a>
                </nav>
            @endif
        @endauth
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6 @yield('main-padding', 'pb-28')">
        {{-- Повідомлення показує JS спливаючим вікном і прибирає ці блоки.
             Без JS вони лишаються на місці звичайною смужкою. --}}
        @if (session('status'))
            <div data-flash="status"
                 class="mb-4 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div data-flash="error"
                 class="mb-4 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="border-t border-ink-200 bg-white/80 backdrop-blur">
        <div class="mx-auto max-w-5xl px-4 py-5 text-sm text-ink-500">
            @php $contacts = \App\Models\Setting::get('school_contacts'); @endphp

            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <span>{{ \App\Models\Setting::get('school_name') ?: 'Шкільна їдальня' }}</span>
                @if ($contacts)
                    <span>{{ $contacts }}</span>
                @endif
            </div>
        </div>
    </footer>

    @yield('sticky-bar')
</body>
</html>

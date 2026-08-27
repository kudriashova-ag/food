@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();

    $ownCabinet = match (true) {
        $user?->isAdmin() => ['url' => '/admin', 'label' => 'Перейти в адмінпанель'],
        $user?->isSupplier() => ['url' => '/supplier', 'label' => 'Перейти в кабінет постачальника'],
        $user?->isStudent() => ['url' => '/', 'label' => 'Перейти в мій кабінет'],
        default => null,
    };
@endphp
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Кабінет недоступний</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f4f5;
            --card: #ffffff;
            --text: #18181b;
            --muted: #71717a;
            --border: #e4e4e7;
            --accent: #f59e0b;
            --accent-text: #18181b;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #18181b;
                --card: #27272a;
                --text: #fafafa;
                --muted: #a1a1aa;
                --border: #3f3f46;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        .card {
            width: 100%;
            max-width: 32rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 2rem;
            text-align: center;
        }

        h1 { margin: 0 0 0.75rem; font-size: 1.5rem; }

        p { margin: 0 0 1.5rem; color: var(--muted); }

        .who {
            display: inline-block;
            margin-bottom: 1.5rem;
            padding: 0.35rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.875rem;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }

        .btn {
            display: inline-block;
            padding: 0.6rem 1.25rem;
            border-radius: 0.5rem;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            font: inherit;
            font-size: 0.95rem;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--accent-text);
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Цей кабінет вам недоступний</h1>

        @if ($user)
            <p>Ваш обліковий запис має інші права доступу. Щоб увійти під іншим акаунтом, спершу вийдіть із поточного.</p>
            <span class="who">Ви увійшли як {{ $user->name }} — {{ $user->role->label() }}</span>
        @else
            <p>Сторінка доступна лише після входу з відповідними правами.</p>
        @endif

        <div class="actions">
            @if ($ownCabinet)
                <a class="btn btn-primary" href="{{ $ownCabinet['url'] }}">{{ $ownCabinet['label'] }}</a>
            @endif

            @if ($user)
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn">Вийти</button>
                </form>
            @else
                <a class="btn btn-primary" href="/">На головну</a>
            @endif
        </div>
    </main>
</body>
</html>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сторінка застаріла</title>
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
        <h1>Сторінка застаріла</h1>
        <p>Ви, схоже, довго не заходили на сторінку — вона застаріла, і дію потрібно повторити. Це не помилка, а звичайний захист: просто оновіть сторінку й спробуйте ще раз.</p>

        <div class="actions">
            <button type="button" class="btn btn-primary" onclick="location.reload()">Оновити сторінку</button>
            <a class="btn" href="/">На головну</a>
        </div>
    </main>
</body>
</html>

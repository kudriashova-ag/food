@extends('layouts.app')

@section('title', 'Інформація про сервіс')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Інформація про сервіс</h1>
        <p class="mt-1 text-ink-500">Замовлення шкільного харчування онлайн.</p>
    </div>

    @if ($extra)
        <div class="card mb-4 border-l-4 border-l-brand-500 p-5">
            <p class="whitespace-pre-line text-sm leading-relaxed">{{ $extra }}</p>
        </div>
    @endif

    <div class="card mb-4 p-5 sm:p-6">
        <h2 class="mb-2 text-lg font-bold">Що це таке</h2>
        <p class="text-sm leading-relaxed text-ink-700">
            Через цей сайт учні та батьки замовляють харчування в шкільній їдальні:
            обирають страви на потрібні дні, бачать суму й можуть скасувати замовлення,
            якщо плани змінилися. Постачальники отримують готовий список і знають,
            скільки порцій готувати.
        </p>
    </div>

    <div class="card mb-4 overflow-hidden">
        <h2 class="border-b border-ink-100 bg-ink-50/60 px-5 py-3 text-lg font-bold">Як замовити</h2>

        <ol class="divide-y divide-ink-100">
            @foreach ([
                ['Оберіть постачальника', 'На головній сторінці показані всі, хто годує вашу школу. Відкрийте будь-якого — побачите меню на найближчі дні.'],
                ['Складіть день', 'Позначте страви на потрібну дату. Сума за день рахується одразу, поки ви обираєте.'],
                ['Додайте день у кошик', 'Кнопка внизу дня. Так само додайте інші дні — хоч на весь тиждень.'],
                ['Увійдіть і підтвердьте', 'Логін і пароль видає школа. Кошик, зібраний до входу, не загубиться.'],
            ] as $index => [$title, $text])
                <li class="flex gap-4 px-5 py-4">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                                 bg-brand-500 text-sm font-bold text-deep-900">{{ $index + 1 }}</span>
                    <div>
                        <div class="font-semibold">{{ $title }}</div>
                        <p class="mt-0.5 text-sm text-ink-600">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <h2 class="mb-2 font-bold">Терміни замовлення</h2>
            <p class="text-sm leading-relaxed text-ink-700">
                Кожен постачальник закриває приймання завчасно — щоб устигнути закупити
                продукти. Час до закриття показаний біля кожного дня в меню. Після
                закриття день стає недоступним.
            </p>
        </div>

        <div class="card p-5">
            <h2 class="mb-2 font-bold">Скасування</h2>
            <p class="text-sm leading-relaxed text-ink-700">
                Замовлення можна скасувати на сторінці «Мої замовлення» — цілим днем
                або окремими стравами. Термін скасування теж свій у кожного
                постачальника й показаний поруч із кнопкою.
            </p>
        </div>

        <div class="card p-5">
            <h2 class="mb-2 font-bold">Сповіщення</h2>
            <p class="text-sm leading-relaxed text-ink-700">
                Підтвердження замовлення надходить на пошту. У «Налаштуваннях» можна
                підключити Telegram і змінити адресу пошти.
            </p>
        </div>

        <div class="card p-5">
            <h2 class="mb-2 font-bold">Оплата</h2>
            <p class="text-sm leading-relaxed text-ink-700">
                Сайт не приймає оплату — він лише збирає замовлення. Порядок розрахунків
                визначає школа.
            </p>
        </div>
    </div>

    <div class="card mt-4 flex flex-col items-start gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="font-semibold">Не знайшли відповіді?</div>
            <p class="text-sm text-ink-500">Напишіть нам — відповімо на пошту.</p>
        </div>

        <a href="{{ route('support.help') }}" class="btn-primary shrink-0">Поставити питання</a>
    </div>
@endsection

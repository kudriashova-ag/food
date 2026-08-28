@php
    $user = auth()->user();
    $student = $user?->student;

    // Показуємо тільки учневі й лише поки Telegram не підключений:
    // коли підключений, налаштовувати вже нічого.
    $telegramConnected = $student?->telegramLinks()->where('is_active', true)->exists() ?? false;
    $email = $user?->email;
@endphp

@if ($student !== null && ! $telegramConnected)
    <div class="card mb-4 flex flex-col gap-3 border-l-4 border-l-brand-500 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-deep-700" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>

            <div class="text-sm">
                @if ($email)
                    <div>
                        Сповіщення про замовлення надходять на
                        <span class="font-semibold text-deep-800">{{ $email }}</span>.
                    </div>
                    <p class="mt-0.5 text-ink-500">
                        Адресу можна змінити, а ще — підключити Telegram, щоб отримувати їх швидше.
                    </p>
                @else
                    <div class="font-semibold text-deep-800">Сповіщення нікуди не надходять</div>
                    <p class="mt-0.5 text-ink-500">
                        Вкажіть e-mail або підключіть Telegram — інакше підтвердження замовлення ви не отримаєте.
                    </p>
                @endif
            </div>
        </div>

        <a href="{{ route('settings') }}" class="btn-accent shrink-0 self-start sm:self-auto">
            Налаштувати
        </a>
    </div>
@endif

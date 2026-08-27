@php
    $links = $this->getTelegramLinks();
    $recipients = $this->getRecipients();
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        @if ($this->telegramLink)
            <x-filament::section>
                <x-slot name="heading">Посилання для підключення</x-slot>
                <x-slot name="description">Дійсне 15 хвилин. Відкрийте його в Telegram і натисніть «Старт».</x-slot>

                <a href="{{ $this->telegramLink }}" target="_blank" rel="noopener"
                   class="break-all text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                    {{ $this->telegramLink }}
                </a>
            </x-filament::section>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit">Зберегти</x-filament::button>
            </div>
        </form>

        <x-filament::section>
            <x-slot name="heading">Куди надсилаємо</x-slot>

            <div class="flex flex-col gap-4 text-sm">
                <div>
                    <div class="mb-1 font-medium text-gray-950 dark:text-white">Пошта</div>

                    @if ($recipients === [])
                        <p class="text-danger-600 dark:text-danger-400">
                            Адреси немає — ані в полі для звітів, ані в обліковому записі. Зведення надсилатися не буде.
                        </p>
                    @else
                        <p class="text-gray-500 dark:text-gray-400">{{ implode(', ', $recipients) }}</p>
                    @endif
                </div>

                <div>
                    <div class="mb-1 font-medium text-gray-950 dark:text-white">Telegram</div>

                    @if (! $this->isTelegramAvailable())
                        <p class="text-gray-500 dark:text-gray-400">Бот ще не налаштований школою.</p>
                    @elseif ($links->isEmpty())
                        <p class="text-gray-500 dark:text-gray-400">
                            Чатів не підключено. Скористайтеся кнопкою «Підключити Telegram» угорі —
                            можна підключити кілька, наприклад кухаря й менеджера.
                        </p>
                    @else
                        <ul class="flex flex-col gap-2">
                            @foreach ($links as $link)
                                <li class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 ring-1 ring-gray-950/5 dark:ring-white/10">
                                    <div>
                                        <div class="font-medium text-gray-950 dark:text-white">
                                            {{ $link->username ? '@'.$link->username : 'Чат '.$link->chat_id }}
                                        </div>
                                        <div @class([
                                            'text-xs',
                                            'text-gray-500 dark:text-gray-400' => $link->is_active,
                                            'text-warning-600 dark:text-warning-400' => ! $link->is_active,
                                        ])>
                                            {{ $link->is_active
                                                ? 'Підключено '.$link->linked_at?->translatedFormat('d.m.Y')
                                                : 'Неактивний — бота заблоковано' }}
                                        </div>
                                    </div>

                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="disconnectTelegram({{ $link->id }})"
                                        wire:confirm="Відключити цей чат?">
                                        Відключити
                                    </x-filament::button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

@php
    /** @var \App\Models\Order $order */
    $order = $this->getRecord();
    $linesByDate = $this->getLinesByDate();
    $total = $this->getTotal();
    $student = $order->student;

    $facts = [
        'Учень' => $student->full_name,
        'Клас' => $order->schoolClass?->title ?? $student->schoolClass?->title ?? '—',
        'Номер замовлення' => $order->number,
        'Оформлено' => $order->placed_at->translatedFormat('d.m.Y, H:i'),
    ];
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <x-filament::section>
            <x-slot name="heading">Хто замовив</x-slot>

            <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($facts as $label => $value)
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}:</dt>
                        <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Склад замовлення</x-slot>
            <x-slot name="description">Показані лише ваші страви.</x-slot>

            <div class="flex flex-col gap-6">
                @foreach ($linesByDate as $lines)
                    @php $dayTotal = $lines->reject->isCancelled()->sum(fn ($line) => $line->subtotal()); @endphp

                    <div class="overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                        <div class="bg-gray-50 px-4 py-2.5 dark:bg-white/5">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $lines->first()->service_date->translatedFormat('l, d.m.Y') }}
                            </h3>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-y border-gray-200 bg-gray-50/50 dark:border-white/10 dark:bg-white/5">
                                        <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Страва</th>
                                        <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">К-сть</th>
                                        <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Ціна</th>
                                        <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Сума</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($lines as $line)
                                        <tr @class(['text-gray-400 dark:text-gray-500' => $line->isCancelled()])>
                                            <td class="px-4 py-2.5">
                                                <span @class(['font-medium text-gray-950 dark:text-white' => ! $line->isCancelled(), 'line-through' => $line->isCancelled()])>
                                                    {{ $line->dish_name }}
                                                </span>

                                                @if ($line->section_title)
                                                    <span class="text-xs text-gray-400">· {{ $line->section_title }}</span>
                                                @endif

                                                @if ($line->isCancelled())
                                                    <span class="text-xs">
                                                        (скасовано {{ $line->cancelled_at?->translatedFormat('d.m H:i') }})
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $line->quantity }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums">
                                                {{ number_format((float) $line->unit_price, 2, ',', ' ') }}
                                            </td>
                                            <td @class([
                                                'px-4 py-2.5 text-right tabular-nums',
                                                'font-medium text-gray-950 dark:text-white' => ! $line->isCancelled(),
                                            ])>
                                                {{ number_format($line->subtotal(), 2, ',', ' ') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>

                                <tfoot>
                                    <tr class="border-t border-gray-200 bg-gray-50/50 dark:border-white/10 dark:bg-white/5">
                                        <td colspan="3" class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">
                                            Разом за день
                                        </td>
                                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                            {{ number_format($dayTotal, 2, ',', ' ') }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endforeach

                <div class="flex items-baseline justify-between rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">Усього за вашими стравами</span>
                    <span class="text-lg font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format($total, 2, ',', ' ') }} грн
                    </span>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

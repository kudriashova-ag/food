@php
    $summary = $this->getSummary();
    $classes = $this->getHandoutList();
    $date = $this->getDate();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">
                Зведення на {{ $date->translatedFormat('d.m.Y') }}
            </x-slot>

            <x-slot name="description">
                Скільки чого готувати. Скасовані позиції не враховуються.
            </x-slot>

            @if ($summary['dishes']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Замовлень на цю дату немає.</p>
            @else
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($summary['dishes'] as $dish)
                            <tr>
                                <td class="py-1.5">{{ $dish['name'] }}</td>
                                <td class="py-1.5 text-right font-medium tabular-nums">{{ $dish['quantity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 dark:border-white/20">
                        <tr>
                            <td class="pt-2 font-medium">Разом позицій</td>
                            <td class="pt-2 text-right font-semibold tabular-nums">{{ $summary['positions'] }}</td>
                        </tr>
                        <tr>
                            <td class="font-medium">Учнів</td>
                            <td class="text-right font-semibold tabular-nums">{{ $summary['students'] }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Список для видачі</x-slot>
            <x-slot name="description">По класах — щоб роздавати без плутанини.</x-slot>

            @forelse ($classes as $class)
                <div class="mb-4 last:mb-0">
                    <h3 class="mb-1 font-semibold">{{ $class['class'] }}</h3>

                    <ul class="space-y-1 text-sm">
                        @foreach ($class['students'] as $student)
                            <li class="flex flex-wrap gap-x-2">
                                <span class="font-medium">{{ $student['name'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">— {{ $student['dishes'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Замовлень на цю дату немає.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>

@php
    $days = $this->getDays();
    $missing = $this->getMissingStudents();
    $supplierNames = $days->flatMap(fn (array $day): array => $day['suppliers']->keys()->all())->unique()->values();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">Замовлення по днях</x-slot>
        <x-slot name="description">Кількість порцій за активними позиціями.</x-slot>

        @if ($supplierNames->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">За цей період замовлень немає.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left dark:border-white/10">
                        <tr>
                            <th class="py-2 pr-3 font-medium">День</th>
                            @foreach ($supplierNames as $name)
                                <th class="py-2 pr-3 text-right font-medium">{{ $name }}</th>
                            @endforeach
                            <th class="py-2 pr-3 text-right font-medium">Позицій</th>
                            <th class="py-2 text-right font-medium">Учнів</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($days as $day)
                            <tr>
                                <td class="py-1.5 pr-3">{{ $day['date']->translatedFormat('D, d.m') }}</td>
                                @foreach ($supplierNames as $name)
                                    <td class="py-1.5 pr-3 text-right tabular-nums">
                                        {{ $day['suppliers'][$name] ?? '—' }}
                                    </td>
                                @endforeach
                                <td class="py-1.5 pr-3 text-right font-medium tabular-nums">{{ $day['positions'] }}</td>
                                <td class="py-1.5 text-right font-medium tabular-nums">{{ $day['students'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Не замовляли на {{ $this->getMissingDate()->translatedFormat('d.m.Y') }}
        </x-slot>
        <x-slot name="description">
            Активні учні, у яких на цю дату немає жодної позиції. Усього: {{ $missing->count() }}.
        </x-slot>

        @if ($missing->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Замовили всі.</p>
        @else
            @foreach ($missing->groupBy(fn ($student) => $student->schoolClass?->title ?? 'Без класу') as $class => $students)
                <div class="mb-3 last:mb-0">
                    <h3 class="mb-1 font-semibold">{{ $class }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $students->pluck('full_name')->implode(', ') }}
                    </p>
                </div>
            @endforeach
        @endif
    </x-filament::section>
</x-filament-panels::page>

@php
    $report = $this->getReport();
    $columns = $report['columns'];
    $rows = $report['rows'];
    [$from, $to] = $this->getPeriod();

    // Шапка у два рядки, як в Excel: зверху день над усіма його секціями.
    $days = $columns->groupBy(fn (array $column): string => $column['date']->toDateString());

    // Дні відокремлено товстою рамкою, як і в Excel: ліворуч від першої колонки
    // кожного дня й праворуч від останньої колонки таблиці.
    $dayEdge = 'border-l-4 border-l-gray-400 dark:border-l-gray-500';
    $tableEdge = 'border-r-4 border-r-gray-400 dark:border-r-gray-500';
    $lastKey = $columns->last()['key'] ?? null;
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">
            {{ $from->equalTo($to)
                ? 'Замовлення на '.$from->translatedFormat('d.m.Y')
                : 'Замовлення з '.$from->translatedFormat('d.m').' по '.$to->translatedFormat('d.m.Y') }}
        </x-slot>

        <x-slot name="description">
            Хто що замовив, по днях і секціях меню. Скасовані позиції не враховуються.
        </x-slot>

        @if ($columns->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">За цей період немає ні меню, ні замовлень.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-100 dark:bg-white/5">
                            <th rowspan="2" class="border border-gray-200 px-2 py-2 text-left font-semibold dark:border-white/10">№</th>
                            <th rowspan="2" class="min-w-48 border border-gray-200 px-3 py-2 text-left font-semibold dark:border-white/10">Прізвище, ім'я</th>
                            <th rowspan="2" class="border border-gray-200 px-3 py-2 text-left font-semibold dark:border-white/10">Клас</th>

                            @foreach ($days as $dayColumns)
                                <th colspan="{{ $dayColumns->count() }}"
                                    @class([
                                        'border border-gray-200 px-3 py-2 text-center font-semibold capitalize dark:border-white/10',
                                        $dayEdge,
                                        $tableEdge => $loop->last,
                                    ])>
                                    {{ $dayColumns->first()['date']->translatedFormat('l, d.m') }}
                                </th>
                            @endforeach
                        </tr>

                        <tr class="bg-gray-50 dark:bg-white/5">
                            @foreach ($columns as $column)
                                <th @class([
                                        'min-w-36 border border-gray-200 px-3 py-2 text-left align-top font-medium dark:border-white/10',
                                        $dayEdge => $column['first_of_day'],
                                        $tableEdge => $column['key'] === $lastKey,
                                    ])>
                                    {{ $column['section_title'] }}

                                    @if ($column['price'] !== null)
                                        <span class="font-normal text-gray-500 dark:text-gray-400">
                                            ({{ rtrim(rtrim(number_format($column['price'], 2, ',', ' '), '0'), ',') }} грн)
                                        </span>
                                    @endif

                                    @if ($column['is_complex'] && $column['complex_dishes'])
                                        <div class="mt-0.5 text-xs font-normal text-gray-500 dark:text-gray-400">
                                            {{ $column['complex_dishes'] }}
                                        </div>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($rows as $index => $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="border border-gray-200 px-2 py-1.5 tabular-nums text-gray-500 dark:border-white/10">{{ $index + 1 }}</td>
                                <td class="border border-gray-200 px-3 py-1.5 font-medium dark:border-white/10">{{ $row['full_name'] }}</td>
                                <td class="border border-gray-200 px-3 py-1.5 dark:border-white/10">{{ $row['class'] }}</td>

                                @foreach ($columns as $column)
                                    <td @class([
                                            'border border-gray-200 px-3 py-1.5 dark:border-white/10',
                                            $dayEdge => $column['first_of_day'],
                                            $tableEdge => $column['key'] === $lastKey,
                                        ])>
                                        {{ $row['cells'][$column['key']] ?? '' }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 3 + $columns->count() }}"
                                    class="border border-gray-200 px-3 py-3 text-center text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    Замовлень за цей період немає.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

@php
    $rows = $this->getRows();
    $summary = $this->getSummary();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($this->lastResult)
        <x-filament::section>
            <x-slot name="heading">Результат останнього імпорту</x-slot>
            <p class="text-sm">{{ $this->lastResult }}</p>
        </x-filament::section>
    @endif

    @if ($this->hasFile())
        @if ($rows->isEmpty())
            <x-filament::section>
                <x-slot name="heading">Файл не розпізнано</x-slot>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    У шапці мають бути колонки «ПІБ» і «Логін». Перевірте, що перший рядок файлу — це заголовки.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Що зміниться</x-slot>
                <x-slot name="description">
                    Нічого ще не записано. Перевірте таблицю й натисніть «Записати в базу».
                </x-slot>

                <div class="mb-4 flex flex-wrap gap-6 text-sm">
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Створиться</div>
                        <div class="text-2xl font-semibold text-success-600">{{ $summary['create'] }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Оновиться</div>
                        <div class="text-2xl font-semibold text-info-600">{{ $summary['update'] }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">З помилками</div>
                        <div class="text-2xl font-semibold {{ $summary['error'] > 0 ? 'text-danger-600' : '' }}">
                            {{ $summary['error'] }}
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left dark:border-white/10">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Рядок</th>
                                <th class="py-2 pr-3 font-medium">ПІБ</th>
                                <th class="py-2 pr-3 font-medium">Логін</th>
                                <th class="py-2 pr-3 font-medium">E-mail</th>
                                <th class="py-2 font-medium">Дія</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($rows as $row)
                                <tr @class(['bg-danger-50/50 dark:bg-danger-500/10' => ! $row->isValid()])>
                                    <td class="py-1.5 pr-3 text-gray-400">{{ $row->number }}</td>
                                    <td class="py-1.5 pr-3">{{ $row->fullName ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $row->login ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 text-gray-500">{{ $row->email ?? '—' }}</td>
                                    <td class="py-1.5">
                                        @if ($row->isValid())
                                            <span @class([
                                                'text-success-600' => $row->action === \App\Services\Import\TeacherImportRow::ACTION_CREATE,
                                                'text-info-600' => $row->action === \App\Services\Import\TeacherImportRow::ACTION_UPDATE,
                                            ])>{{ $row->actionLabel() }}</span>
                                        @else
                                            <span class="text-danger-600">{{ $row->error }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

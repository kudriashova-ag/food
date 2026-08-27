<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Звіти для кухні (ТЗ, п. 10.3). Рахуються тільки активні позиції —
 * скасоване зникає зі звіту, як і вимагає п. 8.2.
 */
class KitchenReportService
{
    /**
     * Зведення на день: скільки чого готувати.
     *
     * @return array{dishes: Collection<int, array{name: string, quantity: int}>, positions: int, students: int}
     */
    public function dailySummary(Supplier|int $supplier, CarbonInterface|string $date): array
    {
        $lines = $this->activeLines($supplier, $date)->get();

        $dishes = $lines
            ->groupBy('dish_name')
            ->map(fn (Collection $group, string $name): array => [
                'name' => $name,
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->sortByDesc('quantity')
            ->values();

        return [
            'dishes' => $dishes,
            'positions' => (int) $lines->sum('quantity'),
            'students' => $lines->pluck('student_id')->unique()->count(),
        ];
    }

    /**
     * Список для видачі: клас → учень → страви.
     *
     * @return Collection<int, array{class: string, students: Collection<int, array{name: string, dishes: string}>}>
     */
    public function handoutList(Supplier|int $supplier, CarbonInterface|string $date): Collection
    {
        $lines = $this->activeLines($supplier, $date)
            ->with(['student.schoolClass', 'student'])
            ->get();

        return $lines
            ->groupBy(fn (OrderLine $line): string => $line->student->schoolClass?->title ?? 'Без класу')
            ->map(fn (Collection $classLines, string $class): array => [
                'class' => $class,
                'students' => $classLines
                    ->groupBy('student_id')
                    ->map(fn (Collection $studentLines): array => [
                        'name' => $studentLines->first()->student->full_name,
                        'dishes' => $studentLines
                            ->map(fn (OrderLine $line): string => $line->quantity > 1
                                ? "{$line->dish_name} ×{$line->quantity}"
                                : $line->dish_name)
                            ->implode(', '),
                    ])
                    ->sortBy('name')
                    ->values(),
            ])
            ->sortKeys()
            ->values();
    }

    private function activeLines(Supplier|int $supplier, CarbonInterface|string $date)
    {
        return OrderLine::query()
            ->where('supplier_id', $supplier instanceof Supplier ? $supplier->id : $supplier)
            ->whereDate('service_date', $date)
            ->active();
    }
}

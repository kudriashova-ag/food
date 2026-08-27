<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Зведення по всій школі (ТЗ, п. 11). */
class SchoolSummaryService
{
    /**
     * Замовлення по днях і постачальниках.
     *
     * @return Collection<int, array{date: CarbonImmutable, suppliers: Collection<string, int>, positions: int, students: int}>
     */
    public function byDay(CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $lines = OrderLine::query()
            // whereDate, а не whereBetween: у колонці лежить дата з часом, тож
            // рядкове порівняння відкидало б останній день діапазону.
            ->whereDate('service_date', '>=', $start->toDateString())
            ->whereDate('service_date', '<=', $end->toDateString())
            ->active()
            ->with('supplier')
            ->get();

        $days = collect();

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dayLines = $lines->filter(
                fn (OrderLine $line): bool => $line->service_date->toDateString() === $date->toDateString(),
            );

            $days->push([
                'date' => $date,
                'suppliers' => $dayLines
                    ->groupBy(fn (OrderLine $line): string => $line->supplier->name)
                    ->map(fn (Collection $group): int => (int) $group->sum('quantity')),
                'positions' => (int) $dayLines->sum('quantity'),
                'students' => $dayLines->pluck('student_id')->unique()->count(),
            ]);
        }

        return $days;
    }

    /**
     * Учні, які не замовляли на цю дату — щоб школа бачила, кого немає в списках.
     *
     * @return Collection<int, Student>
     */
    public function studentsWithoutOrders(CarbonInterface|string $date): Collection
    {
        $orderedStudentIds = OrderLine::query()
            ->whereDate('service_date', $date)
            ->active()
            ->distinct()
            ->pluck('student_id');

        return Student::query()
            ->active()
            ->whereNotIn('id', $orderedStudentIds)
            ->with('schoolClass')
            ->get()
            ->sortBy([
                fn (Student $a, Student $b): int => ($a->schoolClass?->grade ?? 0) <=> ($b->schoolClass?->grade ?? 0),
                fn (Student $a, Student $b): int => strcmp($a->full_name, $b->full_name),
            ])
            ->values();
    }
}

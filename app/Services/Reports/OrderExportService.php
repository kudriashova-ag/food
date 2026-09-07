<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Дані для Excel-експорту замовлень за період: один рядок на учня чи
 * вчителя, по колонці на кожен день періоду з сумою замовленого цього
 * дня, і підсумкова колонка «Всього до сплати».
 *
 * Скасовані позиції (OrderLineStatus::Cancelled) до сум не входять —
 * за них учень/вчитель не платить.
 */
class OrderExportService
{
    /**
     * @return Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>
     */
    public function pupilRows(CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return $this->rows($from, $to, fn () => Student::query()->pupils()->active()->with('schoolClass'));
    }

    /**
     * @return Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>
     */
    public function teacherRows(CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return $this->rows($from, $to, fn () => Student::query()->teachers()->active());
    }

    /**
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<Student>  $query
     * @return Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>
     */
    private function rows(CarbonInterface|string $from, CarbonInterface|string $to, callable $query): Collection
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $students = $query()->orderBy('full_name')->get();

        if ($students->isEmpty()) {
            return collect();
        }

        // Одним запитом — суми по кожному учню/дню одразу, а не по студенту в циклі.
        $sums = OrderLine::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereDate('service_date', '>=', $start->toDateString())
            ->whereDate('service_date', '<=', $end->toDateString())
            ->active()
            ->get()
            ->groupBy('student_id')
            ->map(fn (Collection $lines): Collection => $lines
                ->groupBy(fn (OrderLine $line): string => $line->service_date->toDateString())
                ->map(fn (Collection $dayLines): float => $dayLines->sum(fn (OrderLine $line): float => $line->subtotal())));

        return $students
            ->values()
            ->map(function (Student $student, int $index) use ($sums, $start, $end): array {
                $byDate = [];
                $total = 0.0;

                for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                    $amount = (float) ($sums->get($student->id)?->get($date->toDateString()) ?? 0);
                    $byDate[$date->toDateString()] = $amount;
                    $total += $amount;
                }

                return [
                    'number' => $index + 1,
                    'full_name' => $student->full_name,
                    'class' => $student->schoolClass?->title ?? '',
                    'by_date' => $byDate,
                    'total' => $total,
                ];
            });
    }
}

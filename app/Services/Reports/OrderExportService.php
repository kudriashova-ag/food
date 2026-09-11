<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use App\Models\Student;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Дані для Excel-експорту замовлень за період, окремим звітом на
 * кожного постачальника: один рядок — учень чи вчитель, по колонці
 * на кожен день періоду з сумою замовленого цього дня саме в цього
 * постачальника, і підсумкова колонка «Разом».
 *
 * У звіт постачальника потрапляють лише ті, хто мав хоч одну активну
 * позицію саме в нього за цей період. День без замовлення всередині
 * періоду все одно показує 0, а не пропуск.
 *
 * Скасовані позиції (OrderLineStatus::Cancelled) до сум не входять —
 * за них учень/вчитель не платить.
 */
class OrderExportService
{
    /**
     * @return Collection<int, array{supplier: Supplier, rows: Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>}>
     */
    public function pupilReportsBySupplier(CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return $this->reportsBySupplier($from, $to, fn () => Student::query()->pupils()->active()->with('schoolClass'));
    }

    /**
     * @return Collection<int, array{supplier: Supplier, rows: Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>}>
     */
    public function teacherReportsBySupplier(CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return $this->reportsBySupplier($from, $to, fn () => Student::query()->teachers()->active());
    }

    /**
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<Student>  $query
     * @return Collection<int, array{supplier: Supplier, rows: Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>}>
     */
    private function reportsBySupplier(CarbonInterface|string $from, CarbonInterface|string $to, callable $query): Collection
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $periodLines = OrderLine::query()
            ->whereDate('service_date', '>=', $start->toDateString())
            ->whereDate('service_date', '<=', $end->toDateString())
            ->active();

        $orderedStudentIds = (clone $periodLines)->distinct()->pluck('student_id');

        $students = $query()
            ->whereIn('id', $orderedStudentIds)
            ->orderBy('full_name')
            ->get()
            ->keyBy('id');

        if ($students->isEmpty()) {
            return collect();
        }

        $lines = (clone $periodLines)
            ->whereIn('student_id', $students->keys())
            ->with('supplier')
            ->get();

        $suppliers = $lines->pluck('supplier')->unique('id')->sortBy('name')->values();

        return $suppliers->map(function (Supplier $supplier) use ($lines, $students, $start, $end): array {
            $supplierLines = $lines->where('supplier_id', $supplier->id)->groupBy('student_id');

            $rows = $supplierLines
                ->keys()
                // Той самий порядок, що й загальний список (за ПІБ), не порядок появи в лініях.
                ->sortBy(fn (int $studentId): string => $students->get($studentId)->full_name)
                ->values()
                ->map(function (int $studentId, int $index) use ($supplierLines, $students, $start, $end): array {
                    $student = $students->get($studentId);
                    $studentLines = $supplierLines->get($studentId);

                    $byDate = [];

                    for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                        $dateKey = $date->toDateString();

                        $byDate[$dateKey] = (float) $studentLines
                            ->filter(fn (OrderLine $line): bool => $line->service_date->toDateString() === $dateKey)
                            ->sum(fn (OrderLine $line): float => $line->subtotal());
                    }

                    return [
                        'number' => $index + 1,
                        'full_name' => $student->full_name,
                        'class' => $student->schoolClass?->title ?? '',
                        'by_date' => $byDate,
                        'total' => array_sum($byDate),
                    ];
                });

            return ['supplier' => $supplier, 'rows' => $rows];
        });
    }
}

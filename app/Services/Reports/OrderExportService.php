<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use App\Models\Student;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Дані для Excel-експорту замовлень за період: один рядок на учня чи
 * вчителя, по колонці на кожен день періоду з сумою замовленого цього
 * дня, далі по колонці на кожного постачальника (сума за весь період),
 * і підсумкова колонка «Всього до сплати».
 *
 * У звіт потрапляють лише ті, хто мав хоч одну активну позицію в цьому
 * періоді — не весь список школи. День чи постачальник без замовлення
 * для такої людини все одно показує 0, а не пропуск.
 *
 * Скасовані позиції (OrderLineStatus::Cancelled) до сум не входять —
 * за них учень/вчитель не платить.
 */
class OrderExportService
{
    /**
     * @return array{rows: Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, by_supplier: array<int, float>, total: float}>, suppliers: Collection<int, Supplier>}
     */
    public function pupilReport(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return $this->report($from, $to, fn () => Student::query()->pupils()->active()->with('schoolClass'));
    }

    /**
     * @return array{rows: Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, by_supplier: array<int, float>, total: float}>, suppliers: Collection<int, Supplier>}
     */
    public function teacherReport(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return $this->report($from, $to, fn () => Student::query()->teachers()->active());
    }

    /**
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<Student>  $query
     * @return array{rows: Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, by_supplier: array<int, float>, total: float}>, suppliers: Collection<int, Supplier>}
     */
    private function report(CarbonInterface|string $from, CarbonInterface|string $to, callable $query): array
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
            ->get();

        if ($students->isEmpty()) {
            return ['rows' => collect(), 'suppliers' => collect()];
        }

        $lines = (clone $periodLines)
            ->whereIn('student_id', $students->pluck('id'))
            ->with('supplier')
            ->get();

        // Лише постачальники, які реально фігурують у цих замовленнях —
        // не весь довідник, щоб не тягнути порожні колонки.
        $suppliers = $lines->pluck('supplier')->unique('id')->sortBy('name')->values();

        $byStudent = $lines->groupBy('student_id');

        $rows = $students
            ->values()
            ->map(function (Student $student, int $index) use ($byStudent, $suppliers, $start, $end): array {
                $studentLines = $byStudent->get($student->id, collect());

                $byDate = [];

                for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                    $dateKey = $date->toDateString();

                    $byDate[$dateKey] = (float) $studentLines
                        ->filter(fn (OrderLine $line): bool => $line->service_date->toDateString() === $dateKey)
                        ->sum(fn (OrderLine $line): float => $line->subtotal());
                }

                $bySupplier = $suppliers
                    ->mapWithKeys(fn (Supplier $supplier): array => [
                        $supplier->id => (float) $studentLines
                            ->filter(fn (OrderLine $line): bool => $line->supplier_id === $supplier->id)
                            ->sum(fn (OrderLine $line): float => $line->subtotal()),
                    ])
                    ->all();

                return [
                    'number' => $index + 1,
                    'full_name' => $student->full_name,
                    'class' => $student->schoolClass?->title ?? '',
                    'by_date' => $byDate,
                    'by_supplier' => $bySupplier,
                    'total' => array_sum($byDate),
                ];
            });

        return ['rows' => $rows, 'suppliers' => $suppliers];
    }
}

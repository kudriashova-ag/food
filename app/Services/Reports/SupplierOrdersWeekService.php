<?php

namespace App\Services\Reports;

use App\Enums\MenuSectionType;
use App\Models\OrderLine;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Дані для тижневого звіту постачальника «хто що замовив».
 *
 * Один рядок — учень чи вчитель, що зробив хоч одне активне замовлення
 * в цього постачальника за період. Колонки — секції меню по днях
 * (Перше / Комплекс / Напій…), у клітинці — назва замовленої страви
 * (для комплексу просто «Комплекс», для решти — повна назва) або
 * порожньо, якщо на цю секцію нічого не замовлено.
 *
 * Скасовані позиції не враховуються.
 */
class SupplierOrdersWeekService
{
    /**
     * @return array{
     *     columns: Collection<int, array{key: string, date: CarbonImmutable, section_title: string, section_type: MenuSectionType|null, price: float|null, is_complex: bool, complex_dishes: string|null}>,
     *     rows: Collection<int, array{full_name: string, class: string, cells: array<string, string>}>
     * }
     */
    public function build(Supplier $supplier, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $lines = OrderLine::query()
            ->where('supplier_id', $supplier->id)
            ->whereDate('service_date', '>=', $start->toDateString())
            ->whereDate('service_date', '<=', $end->toDateString())
            ->active()
            ->with('student.schoolClass')
            ->get();

        if ($lines->isEmpty()) {
            return ['columns' => collect(), 'rows' => collect()];
        }

        return [
            'columns' => $this->columns($lines),
            'rows' => $this->rows($lines),
        ];
    }

    /**
     * Колонки: унікальні пари (дата, назва секції), впорядковані за датою
     * й типовим порядком секцій у меню (комплекс → вибір → додаткові).
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return Collection<int, array{key: string, date: CarbonImmutable, section_title: string, section_type: MenuSectionType|null, price: float|null, is_complex: bool, complex_dishes: string|null}>
     */
    private function columns(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (OrderLine $line): string => $line->service_date->toDateString().'|'.$this->sectionKey($line))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $isComplex = $first->section_type === MenuSectionType::Complex;

                return [
                    'key' => $first->service_date->toDateString().'|'.$this->sectionKey($first),
                    'date' => CarbonImmutable::parse($first->service_date),
                    'section_title' => $first->section_title ?? $first->dish_name,
                    'section_type' => $first->section_type,
                    // У межах однієї секції ціна однакова — беремо з будь-якої позиції.
                    'price' => $first->unit_price !== null ? (float) $first->unit_price : null,
                    'is_complex' => $isComplex,
                    // Для комплексу — склад із dish_name ("Комплекс №1: Салат, Котлета…").
                    'complex_dishes' => $isComplex ? $this->complexDishes($first->dish_name) : null,
                ];
            })
            ->values()
            ->sortBy(fn (array $column): string => sprintf(
                '%s|%d|%s',
                $column['date']->toDateString(),
                $this->sectionOrder($column['section_type']),
                $column['section_title'],
            ))
            ->values();
    }

    /**
     * @param  Collection<int, OrderLine>  $lines
     * @return Collection<int, array{full_name: string, class: string, cells: array<string, string>}>
     */
    private function rows(Collection $lines): Collection
    {
        return $lines
            ->groupBy('student_id')
            ->map(function (Collection $studentLines): array {
                $student = $studentLines->first()->student;

                $cells = [];

                foreach ($studentLines as $line) {
                    $key = $line->service_date->toDateString().'|'.$this->sectionKey($line);

                    $cells[$key] = $line->section_type === MenuSectionType::Complex
                        ? 'Комплекс'
                        : $line->dish_name;
                }

                return [
                    'full_name' => $student->full_name,
                    // Вчитель класу не має — за домовленістю пишемо «Вчитель».
                    'class' => $student->school_class_id === null ? 'Вчитель' : ($student->schoolClass?->title ?? ''),
                    'cells' => $cells,
                ];
            })
            ->sortBy('full_name')
            ->values();
    }

    /** Секції з однаковою назвою в один день — одна колонка. */
    private function sectionKey(OrderLine $line): string
    {
        return $line->section_title ?? $line->dish_name ?? '';
    }

    /** Типовий порядок секцій у меню: комплекс → перше (вибір) → додаткові. */
    private function sectionOrder(?MenuSectionType $type): int
    {
        return match ($type) {
            MenuSectionType::Complex => 0,
            MenuSectionType::Choice => 1,
            MenuSectionType::Extra => 2,
            default => 3,
        };
    }

    /** «Комплекс №1: Салат, Котлета» → «Салат, Котлета». */
    private function complexDishes(?string $dishName): ?string
    {
        if ($dishName === null || ! str_contains($dishName, ': ')) {
            return null;
        }

        return trim(substr($dishName, strpos($dishName, ': ') + 2));
    }
}

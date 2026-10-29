<?php

namespace App\Services\Reports;

use App\Enums\MenuSectionType;
use App\Models\MenuDay;
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
 * Скасовані позиції не враховуються — хіба що з $withCancelled (Google Таблиця):
 * тоді вони теж потрапляють у клітинки, а cancelled позначає, які саме,
 * щоб їх можна було виділити кольором. Активна позиція важливіша за скасовану.
 */
class SupplierOrdersWeekService
{
    /**
     * @return array{
     *     columns: Collection<int, array{key: string, date: CarbonImmutable, section_title: string, section_type: MenuSectionType|null, price: float|null, is_complex: bool, complex_dishes: string|null}>,
     *     rows: Collection<int, array{full_name: string, class: string, cells: array<string, string>, cancelled: array<string, true>}>
     * }
     */
    public function build(Supplier $supplier, CarbonInterface|string $from, CarbonInterface|string $to, bool $withCancelled = false): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $lines = OrderLine::query()
            ->where('supplier_id', $supplier->id)
            ->whereDate('service_date', '>=', $start->toDateString())
            ->whereDate('service_date', '<=', $end->toDateString())
            ->when(! $withCancelled, fn ($query) => $query->active())
            ->with('student.schoolClass')
            ->get();

        // Меню робочих днів: секція має колонку, навіть якщо на неї ніхто не замовив.
        $menuDays = MenuDay::query()
            ->where('supplier_id', $supplier->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->where('is_working_day', true)
            ->with('sections.sectionDishes.dish')
            ->get();

        return [
            'columns' => $this->columns($lines, $menuDays),
            'rows' => $this->rows($lines),
        ];
    }

    /**
     * Колонки: секції меню кожного дня плюс секції із замовлень, яких у меню
     * вже немає (меню змінили після замовлення). Порядок у межах дня:
     * перше → комплекс → додаткові; first_of_day позначає першу колонку дня,
     * щоб відокремити дні товстою рамкою.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @param  Collection<int, MenuDay>  $menuDays
     * @return Collection<int, array{key: string, date: CarbonImmutable, section_title: string, section_type: MenuSectionType|null, price: float|null, is_complex: bool, complex_dishes: string|null, sort: int, first_of_day: bool}>
     */
    private function columns(Collection $lines, Collection $menuDays): Collection
    {
        $columns = collect();

        foreach ($menuDays as $menuDay) {
            foreach ($menuDay->sections as $section) {
                $key = $menuDay->date->toDateString().'|'.$section->title;
                $isComplex = $section->type === MenuSectionType::Complex;
                $dishes = $section->sectionDishes->pluck('dish')->filter();

                $columns[$key] ??= [
                    'key' => $key,
                    'date' => CarbonImmutable::parse($menuDay->date),
                    'section_title' => $section->title,
                    'section_type' => $section->type,
                    'price' => $isComplex
                        ? ($section->price !== null ? (float) $section->price : null)
                        : $this->commonPrice($dishes),
                    'is_complex' => $isComplex,
                    'complex_dishes' => $isComplex && $dishes->isNotEmpty() ? $dishes->pluck('name')->implode(', ') : null,
                    'sort' => (int) $section->sort,
                ];
            }
        }

        $lines
            ->groupBy(fn (OrderLine $line): string => $line->service_date->toDateString().'|'.$this->sectionKey($line))
            ->each(function (Collection $group, string $key) use ($columns): void {
                $first = $group->first();
                $isComplex = $first->section_type === MenuSectionType::Complex;

                $columns[$key] ??= [
                    'key' => $key,
                    'date' => CarbonImmutable::parse($first->service_date),
                    'section_title' => $first->section_title ?? $first->dish_name,
                    'section_type' => $first->section_type,
                    // У межах однієї секції ціна однакова — беремо з будь-якої позиції.
                    'price' => $first->unit_price !== null ? (float) $first->unit_price : null,
                    'is_complex' => $isComplex,
                    // Для комплексу — склад із dish_name ("Комплекс №1: Салат, Котлета…").
                    'complex_dishes' => $isComplex ? $this->complexDishes($first->dish_name) : null,
                    'sort' => PHP_INT_MAX,
                ];
            });

        $previousDate = null;

        return $columns
            ->values()
            ->sortBy([
                fn (array $a, array $b): int => $a['date'] <=> $b['date'],
                fn (array $a, array $b): int => $this->sectionOrder($a['section_type']) <=> $this->sectionOrder($b['section_type']),
                fn (array $a, array $b): int => $a['sort'] <=> $b['sort'],
                fn (array $a, array $b): int => strcmp($a['section_title'], $b['section_title']),
            ])
            ->values()
            ->map(function (array $column) use (&$previousDate): array {
                $date = $column['date']->toDateString();
                $column['first_of_day'] = $date !== $previousDate;
                $previousDate = $date;

                return $column;
            });
    }

    /**
     * Ціна для заголовка секції вибору чи додаткових страв: лише якщо в усіх
     * страв вона однакова (суп 45 грн), інакше в заголовку її не пишемо.
     *
     * @param  Collection<int, \App\Models\Dish>  $dishes
     */
    private function commonPrice(Collection $dishes): ?float
    {
        $prices = $dishes->map(fn ($dish): float => (float) $dish->price)->unique();

        return $prices->count() === 1 ? $prices->first() : null;
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
                $cancelled = [];

                // Скасовані спершу, активні після: якщо в ту саму секцію замовили
                // знову, клітинка показує актуальну страву без позначки скасування.
                foreach ($studentLines->sortBy(fn (OrderLine $line): int => $line->isCancelled() ? 0 : 1) as $line) {
                    $key = $line->service_date->toDateString().'|'.$this->sectionKey($line);

                    $cells[$key] = $line->section_type === MenuSectionType::Complex
                        ? 'Комплекс'
                        : $line->dish_name;

                    if ($line->isCancelled()) {
                        $cancelled[$key] = true;
                    } else {
                        unset($cancelled[$key]);
                    }
                }

                return [
                    'student_id' => (int) $student->id,
                    'full_name' => $student->full_name,
                    // Вчитель класу не має — за домовленістю пишемо «Вчитель».
                    'class' => $student->school_class_id === null ? 'Вчитель' : ($student->schoolClass?->title ?? ''),
                    'class_order' => $this->classOrder($student),
                    'cells' => $cells,
                    'cancelled' => $cancelled,
                ];
            })
            ->sortBy('full_name')
            ->values();
    }

    /**
     * Порядок груп у Google Таблиці: шкільні класи → курси коледжу («1-Курс»,
     * «2-Курс» — класи з літерою «Курс») → вчителі.
     *
     * @return array<int, int|string>
     */
    private function classOrder(\App\Models\Student $student): array
    {
        $class = $student->schoolClass;

        if ($student->school_class_id === null || $class === null) {
            return [2, 0, ''];
        }

        if (mb_strtolower(trim((string) $class->letter)) === 'курс') {
            return [1, (int) $class->grade, ''];
        }

        return [0, (int) $class->grade, mb_strtoupper((string) $class->letter)];
    }

    /** Секції з однаковою назвою в один день — одна колонка. */
    private function sectionKey(OrderLine $line): string
    {
        return $line->section_title ?? $line->dish_name ?? '';
    }

    /** Порядок секцій у звіті: перше (вибір) → комплекс → додаткові. */
    private function sectionOrder(?MenuSectionType $type): int
    {
        return match ($type) {
            MenuSectionType::Choice => 0,
            MenuSectionType::Complex => 1,
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

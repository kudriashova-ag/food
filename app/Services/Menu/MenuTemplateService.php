<?php

namespace App\Services\Menu;

use App\Models\MenuDay;
use App\Models\MenuTemplate;
use App\Models\MenuTemplateDay;
use App\Models\NonWorkingDay;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Застосування шаблонів меню й копіювання тижнів.
 *
 * Відповідність дня шаблону календарній даті:
 *   - цикл 7 днів  — day_index 1..7 це просто день тижня (пн..нд);
 *   - цикл 14 днів — day_index 1..7 це перший тиждень циклу, 8..14 другий;
 *     парність тижня рахується від понеділка тижня, з якого почали застосування.
 *
 * Свята зі шкільного календаря перебивають шаблон: у ці дати день
 * створюється неробочим і порожнім.
 */
class MenuTemplateService
{
    /** @var Collection<int, string> дати свят у діапазоні, який зараз обробляємо */
    private Collection $holidays;

    public function __construct()
    {
        $this->holidays = collect();
    }

    public function apply(
        MenuTemplate $template,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        bool $overwrite = false,
        bool $publish = false,
    ): MenuApplyResult {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $cycleStart = $start->startOfWeek(CarbonInterface::MONDAY);

        $days = $template->days()->with('sections.sectionDishes')->get()->keyBy('day_index');

        $this->loadHolidays($start, $end);

        $result = new MenuApplyResult();

        DB::transaction(function () use ($template, $start, $end, $cycleStart, $days, $overwrite, $publish, &$result): void {
            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                $templateDay = $days->get($this->dayIndexFor($date, $cycleStart, $template->cycle_length));

                if ($templateDay === null) {
                    continue;
                }

                $result = $this->writeDay(
                    supplierId: $template->supplier_id,
                    date: $date,
                    isWorkingDay: $templateDay->is_working_day,
                    sections: $templateDay->sections,
                    overwrite: $overwrite,
                    publish: $publish,
                    result: $result,
                );
            }
        });

        return $result;
    }

    /** Копіювання тижня на інший тиждень: ТЗ, п. 5.4. */
    public function copyWeek(
        Supplier|int $supplier,
        CarbonInterface|string $sourceWeekStart,
        CarbonInterface|string $targetWeekStart,
        bool $overwrite = false,
        bool $publish = false,
    ): MenuApplyResult {
        $supplierId = $supplier instanceof Supplier ? $supplier->id : $supplier;
        $source = CarbonImmutable::parse($sourceWeekStart)->startOfWeek(CarbonInterface::MONDAY);
        $target = CarbonImmutable::parse($targetWeekStart)->startOfWeek(CarbonInterface::MONDAY);

        $sourceDays = MenuDay::query()
            ->where('supplier_id', $supplierId)
            ->whereDate('date', '>=', $source->toDateString())
            ->whereDate('date', '<=', $source->addDays(6)->toDateString())
            ->with('sections.sectionDishes')
            ->get()
            ->keyBy(fn (MenuDay $day): int => $day->date->isoWeekday());

        $this->loadHolidays($target, $target->addDays(6));

        $result = new MenuApplyResult();

        DB::transaction(function () use ($sourceDays, $supplierId, $target, $overwrite, $publish, &$result): void {
            foreach ($sourceDays as $isoWeekday => $sourceDay) {
                $result = $this->writeDay(
                    supplierId: $supplierId,
                    date: $target->addDays($isoWeekday - 1),
                    isWorkingDay: $sourceDay->is_working_day,
                    sections: $sourceDay->sections,
                    overwrite: $overwrite,
                    publish: $publish,
                    result: $result,
                );
            }
        });

        return $result;
    }

    /** Свята читаємо один раз на весь діапазон, а не на кожен день. */
    private function loadHolidays(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $this->holidays = collect(array_keys(NonWorkingDay::titlesBetween($from, $to)));
    }

    private function dayIndexFor(CarbonImmutable $date, CarbonImmutable $cycleStart, int $cycleLength): int
    {
        $isoWeekday = $date->isoWeekday();

        if ($cycleLength <= 7) {
            return $isoWeekday;
        }

        $weeksPassed = (int) floor($cycleStart->diffInDays($date->startOfWeek(CarbonInterface::MONDAY)) / 7);
        $weekInCycle = $weeksPassed % (int) ceil($cycleLength / 7);

        return $weekInCycle * 7 + $isoWeekday;
    }

    /**
     * @param  Collection<int, \App\Models\MenuTemplateSection|\App\Models\MenuSection>  $sections
     */
    private function writeDay(
        int $supplierId,
        CarbonImmutable $date,
        bool $isWorkingDay,
        Collection $sections,
        bool $overwrite,
        bool $publish,
        MenuApplyResult $result,
    ): MenuApplyResult {
        $existing = MenuDay::query()
            ->where('supplier_id', $supplierId)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($existing !== null && ! $overwrite) {
            return $result->with(skipped: 1);
        }

        // Свято перебиває шаблон: день створюється порожнім і неробочим,
        // навіть якщо в шаблоні на цей день тижня є страви.
        if ($this->holidays->contains($date->toDateString())) {
            $isWorkingDay = false;
        }

        $menuDay = $existing ?? new MenuDay([
            'supplier_id' => $supplierId,
            'date' => $date->toDateString(),
        ]);

        $menuDay->fill([
            'is_working_day' => $isWorkingDay,
            'published_at' => $publish ? ($menuDay->published_at ?? now()) : $menuDay->published_at,
        ])->save();

        // Секції переписуємо цілком: часткове злиття давало б непередбачуваний результат.
        $menuDay->sections()->delete();

        if ($isWorkingDay) {
            $this->copySections($menuDay, $sections);
        }

        return $existing === null
            ? $result->with(created: 1)
            : $result->with(updated: 1);
    }

    /**
     * @param  Collection<int, \App\Models\MenuTemplateSection|\App\Models\MenuSection>  $sections
     */
    private function copySections(MenuDay $menuDay, Collection $sections): void
    {
        foreach ($sections as $section) {
            $newSection = $menuDay->sections()->create([
                'type' => $section->type,
                'title' => $section->title,
                'sort' => $section->sort,
            ]);

            // І секція меню, і секція шаблону мають однакову зв'язку sectionDishes.
            foreach ($section->sectionDishes as $item) {
                $newSection->sectionDishes()->create([
                    'dish_id' => $item->dish_id,
                    'sort' => $item->sort,
                ]);
            }
        }
    }

    /** Шаблон створюється з готовим набором днів — далі їх лише заповнюють. */
    public function ensureDays(MenuTemplate $template): void
    {
        foreach (range(1, $template->cycle_length) as $dayIndex) {
            MenuTemplateDay::query()->firstOrCreate([
                'menu_template_id' => $template->id,
                'day_index' => $dayIndex,
            ], [
                // Субота й неділя за замовчуванням неробочі.
                'is_working_day' => ! in_array($dayIndex % 7, [6, 0], true),
            ]);
        }
    }
}

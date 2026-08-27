<?php

namespace Database\Seeders;

use App\Enums\MenuSectionType;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/** Демо-меню на два тижні вперед — щоб було що дивитися локально. */
class DemoMenuSeeder extends Seeder
{
    private const MENU = [
        'smachno' => [
            'complex' => [
                ['name' => 'Куряча котлета', 'price' => 60, 'portion' => '120 г'],
                ['name' => 'Картопляне пюре', 'price' => 35, 'portion' => '200 г'],
            ],
            'choice' => [
                ['name' => 'Борщ', 'price' => 40, 'portion' => '300 мл'],
                ['name' => 'Суп курячий', 'price' => 35, 'portion' => '300 мл'],
            ],
            'extra' => [
                ['name' => 'Вода 0,5 л', 'price' => 15, 'portion' => '0,5 л'],
                ['name' => 'Булочка з маком', 'price' => 20, 'portion' => '80 г'],
            ],
        ],
        'domashnya-kuhnya' => [
            'complex' => [
                ['name' => 'Сирники зі сметаною', 'price' => 45, 'portion' => '150 г'],
            ],
            'choice' => [
                ['name' => 'Компот', 'price' => 15, 'portion' => '250 мл'],
                ['name' => 'Чай', 'price' => 10, 'portion' => '250 мл'],
            ],
            'extra' => [
                ['name' => 'Яблуко', 'price' => 12, 'portion' => '1 шт'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::MENU as $slug => $groups) {
            $supplier = Supplier::query()->where('slug', $slug)->first();

            if ($supplier === null) {
                continue;
            }

            $dishes = [];

            foreach ($groups as $type => $items) {
                foreach ($items as $item) {
                    $dishes[$type][] = Dish::query()->updateOrCreate(
                        ['supplier_id' => $supplier->id, 'name' => $item['name']],
                        ['price' => $item['price'], 'portion' => $item['portion']],
                    );
                }
            }

            $this->fillDays($supplier, $dishes);
        }
    }

    /** @param array<string, array<int, Dish>> $dishes */
    private function fillDays(Supplier $supplier, array $dishes): void
    {
        $date = CarbonImmutable::today();

        for ($offset = 0; $offset < 14; $offset++) {
            $day = $date->addDays($offset);

            // Вихідні пропускаємо.
            if ($day->isoWeekday() >= 6) {
                continue;
            }

            $menuDay = MenuDay::query()->updateOrCreate(
                ['supplier_id' => $supplier->id, 'date' => $day->toDateString()],
                ['is_working_day' => true, 'published_at' => now()],
            );

            if ($menuDay->sections()->exists()) {
                continue;
            }

            $titles = [
                'complex' => 'Комплекс №1',
                'choice' => 'Перша страва',
                'extra' => 'Додатково',
            ];

            $sort = 0;

            foreach (['complex', 'choice', 'extra'] as $type) {
                $section = $menuDay->sections()->create([
                    'type' => MenuSectionType::from($type),
                    'title' => $titles[$type],
                    'sort' => $sort++,
                ]);

                foreach ($dishes[$type] ?? [] as $index => $dish) {
                    $section->sectionDishes()->create(['dish_id' => $dish->id, 'sort' => $index]);
                }
            }
        }
    }
}

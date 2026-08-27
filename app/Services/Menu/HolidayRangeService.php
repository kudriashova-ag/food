<?php

namespace App\Services\Menu;

use App\Models\MenuDay;
use App\Models\NonWorkingDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Канікули задаються діапазоном — інакше на зимові довелося б
 * створювати два десятки записів руками.
 */
class HolidayRangeService
{
    /**
     * @param  bool  $closePublishedDays  зняти з публікації вже створені меню на ці дати
     * @return array{created: int, skipped: int, closed: int}
     */
    public function createRange(
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        string $title,
        ?User $actor = null,
        bool $skipWeekends = false,
        bool $closePublishedDays = true,
    ): array {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $created = 0;
        $skipped = 0;
        $closed = 0;

        DB::transaction(function () use ($start, $end, $title, $actor, $skipWeekends, $closePublishedDays, &$created, &$skipped, &$closed): void {
            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                if ($skipWeekends && $date->isoWeekday() >= 6) {
                    continue;
                }

                // whereDate, а не firstOrCreate: у колонці лежить дата з часом,
                // тож точний збіг рядка не спрацював би і дав дублікат.
                $exists = NonWorkingDay::query()
                    ->whereDate('date', $date->toDateString())
                    ->exists();

                if ($exists) {
                    $skipped++;
                } else {
                    NonWorkingDay::create([
                        'date' => $date->toDateString(),
                        'title' => $title,
                        'created_by' => $actor?->id,
                    ]);

                    $created++;
                }

                if ($closePublishedDays) {
                    $closed += $this->closeMenuDays($date);
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'closed' => $closed];
    }

    /**
     * Меню, вже створене на цю дату, гасимо: інакше воно лишалося б
     * у кабінеті постачальника як робоче й плутало кухню.
     */
    private function closeMenuDays(CarbonImmutable $date): int
    {
        return MenuDay::query()
            ->whereDate('date', $date->toDateString())
            ->where('is_working_day', true)
            ->update(['is_working_day' => false]);
    }

    public function summary(array $result): string
    {
        $parts = ["додано днів: {$result['created']}"];

        if ($result['skipped'] > 0) {
            $parts[] = "вже були позначені: {$result['skipped']}";
        }

        if ($result['closed'] > 0) {
            $parts[] = "закрито наявних меню: {$result['closed']}";
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}

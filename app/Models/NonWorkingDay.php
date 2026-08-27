<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Свято або канікули на рівні школи.
 *
 * У ці дні меню не показується учням, а шаблони й копіювання тижня
 * створюють день як неробочий, навіть якщо в шаблоні він заповнений.
 */
class NonWorkingDay extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'date',
        'title',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function isHoliday(CarbonInterface|string $date): bool
    {
        return static::query()
            ->whereDate('date', $date instanceof CarbonInterface ? $date->toDateString() : $date)
            ->exists();
    }

    /**
     * Назви свят за датами — щоб не робити запит на кожен день у списку.
     *
     * @return array<string, string>  ключ — дата Y-m-d
     */
    public static function titlesBetween(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return static::query()
            ->whereDate('date', '>=', $from instanceof CarbonInterface ? $from->toDateString() : $from)
            ->whereDate('date', '<=', $to instanceof CarbonInterface ? $to->toDateString() : $to)
            ->get()
            ->mapWithKeys(fn (self $day): array => [$day->date->toDateString() => $day->title])
            ->all();
    }

    protected function activityAttributes(): array
    {
        return ['date', 'title'];
    }

    protected static function activityLabel(): string
    {
        return 'Неробочий день';
    }
}

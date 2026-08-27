<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;

class MenuDay extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'supplier_id',
        'date',
        'is_working_day',
        'published_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_working_day' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(MenuSection::class)->orderBy('sort');
    }

    /**
     * Учням показуємо тільки опубліковані робочі дні, яких немає
     * у шкільному календарі свят.
     */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query
            ->where('is_working_day', true)
            ->whereNotNull('published_at')
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('non_working_days')
                ->whereColumn('non_working_days.date', 'menu_days.date'));
    }

    protected function activityAttributes(): array
    {
        return ['date', 'is_working_day', 'published_at'];
    }

    protected static function activityLabel(): string
    {
        return 'Меню дня';
    }

    public function activitySupplierId(): int
    {
        return $this->supplier_id;
    }
}

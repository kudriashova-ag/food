<?php

namespace App\Filament\Resources\Activities\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    /** Людські назви типів записів замість імен класів. */
    public const SUBJECTS = [
        \App\Models\Order::class => 'Замовлення',
        \App\Models\OrderLine::class => 'Позиція замовлення',
        \App\Models\Dish::class => 'Страва',
        \App\Models\MenuDay::class => 'Меню дня',
        \App\Models\DeadlineRule::class => 'Правило дедлайну',
        \App\Models\DeadlineOverride::class => 'Виняток дедлайну',
        \App\Models\Student::class => 'Учень',
        \App\Models\Supplier::class => 'Постачальник',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Коли')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('d.m.Y H:i'))
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('Хто')
                    ->placeholder('система')
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Дія')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label('Об\'єкт')
                    ->formatStateUsing(fn (?string $state): string => self::SUBJECTS[$state] ?? class_basename((string) $state))
                    ->badge()
                    ->toggleable(),

                TextColumn::make('properties')
                    ->label('Деталі')
                    ->formatStateUsing(fn ($state): string => static::formatProperties($state))
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Дата з')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Дата по')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),

                SelectFilter::make('subject_type')
                    ->label('Об\'єкт')
                    ->options(self::SUBJECTS),
            ])
            // Записи журналу не редагуються й не видаляються (ТЗ, п. 13).
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Журнал порожній');
    }

    private static function formatProperties(mixed $properties): string
    {
        $data = $properties instanceof \Illuminate\Support\Collection
            ? $properties->toArray()
            : (array) $properties;

        $parts = [];

        foreach (['reason' => 'причина', 'service_date' => 'дата', 'dish' => 'страва', 'number' => 'номер', 'order_number' => 'замовлення'] as $key => $label) {
            if (filled($data[$key] ?? null)) {
                $parts[] = "{$label}: {$data[$key]}";
            }
        }

        if (! empty($data['past_deadline'])) {
            $parts[] = 'поза дедлайном';
        }

        foreach (['old', 'attributes'] as $group) {
            if (empty($data[$group]) || ! is_array($data[$group])) {
                continue;
            }

            $prefix = $group === 'old' ? 'було' : 'стало';
            $pairs = collect($data[$group])
                ->map(fn ($value, $key): string => "{$key}=".(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))
                ->implode(', ');

            $parts[] = "{$prefix}: {$pairs}";
        }

        return implode(' · ', $parts) ?: '—';
    }

    public static function model(): string
    {
        return Activity::class;
    }
}

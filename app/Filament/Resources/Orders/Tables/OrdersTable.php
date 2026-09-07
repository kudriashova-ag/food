<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Supplier;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('order_dates')
                    ->label('Дата')
                    ->state(fn (Order $record): string => static::dates($record))
                    ->description(fn (Order $record): string => 'оформлено '.$record->placed_at->translatedFormat('d.m.Y H:i')),

                TextColumn::make('student.schoolClass.title')
                    ->label('Клас')
                    ->placeholder('—'),

                TextColumn::make('student.full_name')
                    ->label('Учень / вчитель')
                    ->searchable(),

                TextColumn::make('total_amount')
                    ->label('Сума')
                    ->suffix(' грн')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
            ])
            ->defaultSort('placed_at', 'desc')
            ->filters([
                Filter::make('service_date')
                    ->schema([
                        DatePicker::make('from')->label('Дата з')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Дата по')->native(false)->displayFormat('d.m.Y'),
                    ])
                    // Замовлення може охоплювати кілька днів — показуємо ті, де
                    // хоч одна позиція влучає в діапазон.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereHas(
                            'lines',
                            fn (Builder $l) => $l->whereDate('service_date', '>=', $date),
                        ))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereHas(
                            'lines',
                            fn (Builder $l) => $l->whereDate('service_date', '<=', $date),
                        ))),

                SelectFilter::make('supplier_id')
                    ->label('Постачальник')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $supplierId) => $q->whereHas('lines', fn (Builder $l) => $l->where('supplier_id', $supplierId)),
                    )),

                SelectFilter::make('school_class')
                    ->label('Клас')
                    ->options(fn (): array => SchoolClass::query()
                        ->orderBy('grade')->orderBy('letter')
                        ->get()
                        ->mapWithKeys(fn (SchoolClass $class): array => [$class->id => $class->title])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $classId) => $q->whereHas('student', fn (Builder $s) => $s->where('school_class_id', $classId)),
                    )),

                TernaryFilter::make('has_cancelled')
                    ->label('Скасовані позиції')
                    ->placeholder('Будь-які')
                    ->trueLabel('Є скасовані')
                    ->falseLabel('Без скасованих')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('lines', fn (Builder $l) => $l->cancelled()),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('lines', fn (Builder $l) => $l->cancelled()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make()->label('Відкрити'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Замовлень немає');
    }

    /** Замовлення може охоплювати кілька днів — показуємо діапазон. */
    private static function dates(Order $record): string
    {
        $dates = $record->lines
            ->pluck('service_date')
            ->unique(fn ($date): string => $date->toDateString())
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return '—';
        }

        if ($dates->count() === 1) {
            return $dates->first()->translatedFormat('D, d.m.Y');
        }

        return $dates->first()->translatedFormat('d.m').' – '.$dates->last()->translatedFormat('d.m.Y');
    }
}

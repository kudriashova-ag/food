<?php

namespace App\Filament\Supplier\Resources\Orders\Tables;

use App\Models\Order;
use App\Models\SchoolClass;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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

                TextColumn::make('supplier_dates')
                    ->label('Дата харчування')
                    ->state(fn (Order $record): string => static::dates($record))
                    ->description(fn (Order $record): string => 'оформлено '.$record->placed_at->translatedFormat('d.m.Y H:i')),

                TextColumn::make('student.full_name')
                    ->label('Учень')
                    ->searchable()
                    ->description(fn (Order $record): ?string => $record->schoolClass?->title
                        ?? $record->student->schoolClass?->title),

                TextColumn::make('supplier_positions')
                    ->label('Позицій')
                    ->alignCenter(),

                TextColumn::make('supplier_total')
                    ->label('Сума')
                    ->alignEnd()
                    ->weight('medium')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ').' грн'),
            ])
            ->defaultSort('placed_at', 'desc')
            ->filters([
                Filter::make('service_date')
                    ->schema([
                        DatePicker::make('from')->label('Дата харчування з')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('по')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereHas(
                            'lines',
                            fn (Builder $l) => $l->where('supplier_id', static::supplierId())->whereDate('service_date', '>=', $date),
                        ))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereHas(
                            'lines',
                            fn (Builder $l) => $l->where('supplier_id', static::supplierId())->whereDate('service_date', '<=', $date),
                        ))),

                SelectFilter::make('school_class_id')
                    ->label('Клас')
                    ->options(fn (): array => SchoolClass::query()
                        ->orderBy('grade')->orderBy('letter')
                        ->get()
                        ->mapWithKeys(fn (SchoolClass $class): array => [$class->id => $class->title])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Відкрити'),
            ])
            ->emptyStateHeading('Замовлень немає')
            ->emptyStateDescription('Тут з\'являться замовлення учнів на ваші страви.');
    }

    /** Замовлення може охоплювати кілька днів — показуємо діапазон. */
    private static function dates(Order $record): string
    {
        $dates = $record->lines
            ->where('supplier_id', static::supplierId())
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

    private static function supplierId(): ?int
    {
        return auth()->user()?->supplier_id;
    }
}

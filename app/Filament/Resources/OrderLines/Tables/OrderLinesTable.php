<?php

namespace App\Filament\Resources\OrderLines\Tables;

use App\Enums\OrderLineStatus;
use App\Models\OrderLine;
use App\Models\SchoolClass;
use App\Models\Supplier;
use App\Services\Orders\CancellationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OrderLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.number')
                    ->label('Замовлення')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('service_date')
                    ->label('Дата')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('D, d.m.Y'))
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('Постачальник')
                    ->sortable(),

                TextColumn::make('student.schoolClass.title')
                    ->label('Клас'),

                TextColumn::make('student.full_name')
                    ->label('Учень')
                    ->searchable(),

                TextColumn::make('dish_name')
                    ->label('Страва')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('К-сть')
                    ->alignCenter(),

                TextColumn::make('unit_price')
                    ->label('Ціна')
                    ->suffix(' грн')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (OrderLineStatus $state): string => $state->label())
                    ->color(fn (OrderLineStatus $state): string => $state === OrderLineStatus::Active ? 'success' : 'danger')
                    ->description(fn (OrderLine $record): ?string => $record->cancel_reason),
            ])
            ->defaultSort('service_date', 'desc')
            ->filters([
                Filter::make('service_date')
                    ->schema([
                        DatePicker::make('from')->label('Дата з')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Дата по')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('service_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('service_date', '<=', $date))),

                SelectFilter::make('supplier_id')
                    ->label('Постачальник')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),

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

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(OrderLineStatus::cases())
                        ->mapWithKeys(fn (OrderLineStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->recordActions([
                static::cancelAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::cancelBulkAction(),
                ]),
            ])
            ->emptyStateHeading('Замовлень немає');
    }

    /** Адміністратор скасовує в будь-який момент, але причина обов'язкова (ТЗ, п. 7.4). */
    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Скасувати')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (OrderLine $record): bool => ! $record->isCancelled())
            ->modalHeading('Скасування позиції')
            ->modalSubmitActionLabel('Скасувати позицію')
            ->schema([
                Textarea::make('reason')
                    ->label('Причина')
                    ->required()
                    ->rows(2)
                    ->helperText('Причина зберігається в журналі та показується учневі.'),
            ])
            ->action(function (OrderLine $record, array $data): void {
                app(CancellationService::class)->cancelLine(
                    $record,
                    auth()->user(),
                    reason: $data['reason'],
                    bypassDeadline: true,
                );

                Notification::make()->title('Позицію скасовано')->success()->send();
            });
    }

    private static function cancelBulkAction(): BulkAction
    {
        return BulkAction::make('cancelSelected')
            ->label('Скасувати обрані')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->label('Причина')
                    ->required()
                    ->rows(2),
            ])
            ->action(function (Collection $records, array $data): void {
                $service = app(CancellationService::class);

                foreach ($records as $line) {
                    $service->cancelLine($line, auth()->user(), reason: $data['reason'], bypassDeadline: true);
                }

                Notification::make()->title('Позиції скасовано')->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}

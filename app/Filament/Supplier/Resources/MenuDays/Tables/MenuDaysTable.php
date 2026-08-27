<?php

namespace App\Filament\Supplier\Resources\MenuDays\Tables;

use App\Models\MenuDay;
use App\Models\NonWorkingDay;
use App\Services\Deadlines\DeadlineService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

class MenuDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Дата')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('D, d.m.Y'))
                    // Свято школи постачальник не редагує, але має бачити причину.
                    ->description(fn (MenuDay $record): ?string => NonWorkingDay::query()
                        ->whereDate('date', $record->date->toDateString())
                        ->value('title'))
                    ->sortable(),

                IconColumn::make('is_working_day')
                    ->label('Робочий')
                    ->boolean(),

                TextColumn::make('sections_count')
                    ->label('Секцій')
                    ->counts('sections')
                    ->alignCenter(),

                TextColumn::make('published_at')
                    ->label('Публікація')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state): string => $state ? 'Опубліковано' : 'Чернетка')
                    ->default(null)
                    ->state(fn (MenuDay $record): string => $record->published_at ? 'Опубліковано' : 'Чернетка'),

                TextColumn::make('order_deadline')
                    ->label('Приймання замовлень')
                    ->state(fn (MenuDay $record): string => app(DeadlineService::class)
                        ->for($record->supplier_id, $record->date)
                        ->orderLabel())
                    ->color(fn (MenuDay $record): string => app(DeadlineService::class)
                        ->for($record->supplier_id, $record->date)
                        ->isConfigured() ? 'gray' : 'danger')
                    ->toggleable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                TernaryFilter::make('published')
                    ->label('Публікація')
                    ->placeholder('Усі')
                    ->trueLabel('Тільки опубліковані')
                    ->falseLabel('Тільки чернетки')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('published_at'),
                        false: fn (Builder $query) => $query->whereNull('published_at'),
                    ),

                Filter::make('upcoming')
                    ->label('Від сьогодні')
                    ->query(fn (Builder $query): Builder => $query->whereDate('date', '>=', today()))
                    ->default(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('togglePublication')
                    ->label(fn (MenuDay $record): string => $record->published_at ? 'Зняти з публікації' : 'Опублікувати')
                    ->icon(fn (MenuDay $record): string => $record->published_at ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->requiresConfirmation()
                    ->action(fn (MenuDay $record) => $record->update([
                        'published_at' => $record->published_at ? null : now(),
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label('Опублікувати')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['published_at' => now()]))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Меню ще не створене')
            ->emptyStateDescription('Створіть меню на день або застосуйте шаблон тижня.');
    }
}

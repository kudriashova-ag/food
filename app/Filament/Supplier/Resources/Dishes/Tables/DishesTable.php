<?php

namespace App\Filament\Supplier\Resources\Dishes\Tables;

use App\Models\Dish;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class DishesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('primaryPhoto.path')
                    ->label('Фото')
                    ->disk('public')
                    ->height(48),

                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->portion),

                TextColumn::make('price')
                    ->label('Ціна')
                    ->suffix(' грн')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('allergens.name')
                    ->label('Алергени')
                    ->badge()
                    ->toggleable(),

                IconColumn::make('is_archived')
                    ->label('В архіві')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_archived')
                    ->label('Архів')
                    ->placeholder('Тільки активні')
                    ->trueLabel('Тільки архівовані')
                    ->falseLabel('Тільки активні')
                    ->default(false),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::deleteOrArchiveBulkAction(),
                ]),
            ])
            ->emptyStateHeading('Страв ще немає')
            ->emptyStateDescription('Додайте страви до бібліотеки — далі їх можна ставити в меню будь-якого дня.');
    }

    /**
     * Страву, яку вже поставили в якесь меню чи замовлення, БД фізично видалити
     * не дає (щоб не зламати історію) — тому замість помилки "не видаляється"
     * такі страви архівуємо: вони зникають із вибору для нового меню, лишаючись
     * в історії. Вільні страви видаляються як завжди.
     */
    private static function deleteOrArchiveBulkAction(): BulkAction
    {
        return BulkAction::make('deleteOrArchive')
            ->label('Видалити')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Видалити страви')
            ->modalDescription('Страви, які вже стоять у меню чи в замовленнях, видалити неможливо без втрати історії — їх буде архівовано замість видалення.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $deleted = 0;
                $archived = 0;

                /** @var Dish $dish */
                foreach ($records as $dish) {
                    if ($dish->isInUse()) {
                        $dish->update(['is_archived' => true]);
                        $archived++;
                    } else {
                        $dish->delete();
                        $deleted++;
                    }
                }

                $message = match (true) {
                    $archived === 0 => "Видалено страв: {$deleted}.",
                    $deleted === 0 => "Архівовано страв: {$archived} — вони вже є в меню чи в замовленнях, видалити без втрати історії не можна.",
                    default => "Видалено: {$deleted}, архівовано (уже використані в меню чи замовленнях): {$archived}.",
                };

                Notification::make()
                    ->title('Готово')
                    ->body($message)
                    ->success()
                    ->send();
            });
    }
}

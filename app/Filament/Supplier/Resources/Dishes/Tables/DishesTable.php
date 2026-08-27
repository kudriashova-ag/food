<?php

namespace App\Filament\Supplier\Resources\Dishes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Страв ще немає')
            ->emptyStateDescription('Додайте страви до бібліотеки — далі їх можна ставити в меню будь-якого дня.');
    }
}

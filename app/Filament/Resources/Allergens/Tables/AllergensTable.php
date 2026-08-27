<?php

namespace App\Filament\Resources\Allergens\Tables;

use App\Models\Allergen;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AllergensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('dishes_count')
                    ->label('Страв із міткою')
                    ->counts('dishes')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    // Видалення знімає мітку з усіх страв — попереджаємо явно.
                    ->modalDescription(fn (Allergen $record): string => $record->dishes()->count() > 0
                        ? "Мітку буде знято з {$record->dishes()->count()} страв. Самі страви лишаться."
                        : 'Алерген буде видалено.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Алергенів немає')
            ->emptyStateDescription('Додайте перелік — постачальники позначатимуть ним страви.');
    }
}

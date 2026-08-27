<?php

namespace App\Filament\Supplier\Resources\DeadlineOverrides\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeadlineOverridesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Дата харчування')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('D, d.m.Y'))
                    ->sortable(),

                TextColumn::make('order_deadline_at')
                    ->label('Замовлення до')
                    ->formatStateUsing(fn ($state): string => $state?->translatedFormat('D, d.m, H:i') ?? 'за правилом')
                    ->placeholder('за правилом'),

                TextColumn::make('cancel_deadline_at')
                    ->label('Скасування до')
                    ->formatStateUsing(fn ($state): string => $state?->translatedFormat('D, d.m, H:i') ?? 'за правилом')
                    ->placeholder('за правилом'),

                TextColumn::make('reason')
                    ->label('Причина')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Винятків немає')
            ->emptyStateDescription('Виняток потрібен, коли на конкретну дату дедлайн відрізняється від загального правила — наприклад, перед святом.');
    }
}

<?php

namespace App\Filament\Resources\SupportRequests\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupportRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Коли')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('d.m.Y H:i'))
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Від')
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('message')
                    ->label('Питання')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),

                IconColumn::make('notified_at')
                    ->label('Надіслано')
                    ->boolean()
                    ->tooltip(fn ($record): string => $record->notified_at === null
                        ? 'Жоден канал не спрацював — відповідайте вручну'
                        : 'Доставлено адміністратору'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Відкрити'),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Звернень поки немає');
    }

}

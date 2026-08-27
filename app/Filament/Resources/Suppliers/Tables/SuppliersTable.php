<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('Логотип')
                    ->disk('public')
                    ->height(40),

                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('users.email')
                    ->label('Вхід')
                    ->listWithLineBreaks()
                    ->placeholder('немає акаунта'),

                TextColumn::make('contact_person')
                    ->label('Контактна особа')
                    ->description(fn (Supplier $record): ?string => $record->phone)
                    ->toggleable(),

                IconColumn::make('is_visible')
                    ->label('Видимий')
                    ->boolean(),

                TextColumn::make('dishes_count')
                    ->label('Страв')
                    ->counts('dishes')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make(),
                static::resetPasswordAction(),
            ])
            ->emptyStateHeading('Постачальників немає')
            ->emptyStateDescription('Додайте постачальника — учні побачать його на головній сторінці.');
    }

    private static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Скинути пароль')
            ->icon('heroicon-o-key')
            ->modalHeading('Новий пароль для входу')
            ->modalSubmitActionLabel('Зберегти')
            ->visible(fn (Supplier $record): bool => $record->users()->exists())
            ->schema([
                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->default(fn (): string => Str::password(10, symbols: false))
                    ->helperText('Передайте його постачальнику — після закриття вікна пароль не відновити.'),
            ])
            ->action(function (Supplier $record, array $data): void {
                $record->users()->update(['password' => Hash::make($data['password'])]);

                Notification::make()
                    ->title('Пароль оновлено')
                    ->success()
                    ->send();
            });
    }
}

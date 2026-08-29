<?php

namespace App\Filament\Supplier\Resources\MenuDays\Schemas;

use App\Filament\Supplier\Schemas\MenuSectionsRepeater;
use App\Models\MenuDay;
use App\Models\NonWorkingDay;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MenuDayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Секції одна під одною: список страв широкий, у половині екрана
            // він тісниться, а «День» поруч лишає порожнє місце.
            ->columns(1)
            ->components([
                Section::make('День')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('date')
                            ->label('Дата')
                            ->required()
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->rule(fn (?MenuDay $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $exists = MenuDay::query()
                                    ->where('supplier_id', auth()->user()?->supplier_id)
                                    ->whereDate('date', $value)
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('Меню на цю дату вже створене — відредагуйте наявне.');
                                }
                            }),

                        Toggle::make('is_working_day')
                            ->label('Робочий день')
                            ->default(true)
                            ->live()
                            ->helperText(function (Get $get): string {
                                $holiday = filled($get('date'))
                                    ? NonWorkingDay::query()->whereDate('date', $get('date'))->value('title')
                                    : null;

                                return $holiday !== null
                                    ? "Школа позначила цю дату неробочою: {$holiday}. Меню учням не покажеться."
                                    : 'Вимкніть для свята чи канікул — меню не показується учням.';
                            }),

                        Toggle::make('is_published')
                            ->label('Опубліковано')
                            ->helperText('Неопубліковане меню бачите тільки ви.'),

                        TextInput::make('note')
                            ->label('Примітка')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Секції меню')
                    ->description('Комплекс купується цілком за фіксованою ціною. Choice/Extra підсумовуються окремо, як і раніше.')
                    ->visible(fn (Get $get): bool => (bool) $get('is_working_day'))
                    ->schema([
                        MenuSectionsRepeater::make(),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\NonWorkingDays\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NonWorkingDayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Неробочий день')
                    ->description('У цю дату меню не показується учням, а шаблони створюють день порожнім.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('date')
                            ->label('Дата')
                            ->required()
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'Ця дата вже позначена як неробоча.',
                            ]),

                        TextInput::make('title')
                            ->label('Причина')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Новий рік'),
                    ]),
            ]);
    }
}

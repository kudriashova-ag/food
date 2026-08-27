<?php

namespace App\Filament\Supplier\Resources\MenuTemplates\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MenuTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Шаблон')
                    ->description('Заповніть дні шаблону один раз — далі його можна застосувати на будь-який діапазон дат.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Основний тиждень'),

                        Select::make('cycle_length')
                            ->label('Цикл')
                            ->options([
                                7 => 'Тиждень (7 днів)',
                                14 => 'Два тижні (14 днів)',
                            ])
                            ->default(7)
                            ->required()
                            ->disabledOn('edit')
                            ->helperText('Після створення довжину циклу змінити не можна.'),
                    ]),
            ]);
    }
}

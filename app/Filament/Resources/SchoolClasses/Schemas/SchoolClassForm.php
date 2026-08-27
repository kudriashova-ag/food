<?php

namespace App\Filament\Resources\SchoolClasses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SchoolClassForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Клас')
                    ->columns(3)
                    ->schema([
                        Select::make('grade')
                            ->label('Паралель')
                            ->options(array_combine(range(1, 11), range(1, 11)))
                            ->required(),

                        TextInput::make('letter')
                            ->label('Літера')
                            ->required()
                            ->maxLength(4)
                            ->placeholder('А'),

                        TextInput::make('academic_year')
                            ->label('Навчальний рік')
                            ->numeric()
                            ->required()
                            ->default(fn (): int => now()->month >= 8 ? now()->year : now()->year - 1)
                            ->helperText('Рік початку: 2026 = 2026/27.'),

                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true),
                    ]),
            ]);
    }
}

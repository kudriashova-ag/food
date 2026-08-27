<?php

namespace App\Filament\Supplier\Resources\Dishes\Schemas;

use App\Models\Allergen;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DishForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основне')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('price')
                            ->label('Ціна')
                            ->suffix('грн')
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        TextInput::make('portion')
                            ->label('Вага / об\'єм порції')
                            ->placeholder('200 г, 0,5 л')
                            ->maxLength(64),

                        Textarea::make('description')
                            ->label('Короткий опис')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('composition')
                            ->label('Склад')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Фотографії')
                    ->description('Перше фото — основне, воно показується учням у списку. Порядок можна змінювати перетягуванням.')
                    ->schema([
                        FileUpload::make('photos')
                            ->label('Фотографії')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->required()
                            ->minFiles(1)
                            ->maxFiles(5)
                            ->maxSize(10240)
                            ->disk('public')
                            ->directory('dishes')
                            ->imageEditor()
                            ->helperText('До 5 фото, кожне до 10 МБ.'),
                    ]),

                Section::make('Додатково')
                    ->columns(2)
                    ->schema([
                        Select::make('allergens')
                            ->label('Алергени')
                            ->relationship('allergens', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Показуються учням мітками на картці страви. Якщо потрібного немає — додайте його тут.')
                            // Перелік спільний для школи, тож новий алерген бачитимуть усі.
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Назва')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(table: 'allergens', column: 'name')
                                    ->placeholder('Глютен'),
                            ])
                            ->createOptionUsing(fn (array $data): int => Allergen::create($data)->getKey()),

                        Toggle::make('is_archived')
                            ->label('Архівована')
                            ->helperText('Архівована страва лишається в історії замовлень, але не пропонується при складанні меню.'),
                    ]),
            ]);
    }
}

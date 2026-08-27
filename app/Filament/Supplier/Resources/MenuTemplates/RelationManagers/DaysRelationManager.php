<?php

namespace App\Filament\Supplier\Resources\MenuTemplates\RelationManagers;

use App\Filament\Supplier\Schemas\MenuSectionsRepeater;
use App\Models\MenuTemplateDay;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DaysRelationManager extends RelationManager
{
    protected static string $relationship = 'days';

    protected static ?string $title = 'Дні шаблону';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Toggle::make('is_working_day')
                            ->label('Робочий день')
                            ->default(true)
                            ->live()
                            ->helperText('Вимкніть, якщо в цей день харчування не пропонується.'),
                    ]),

                Section::make('Секції меню')
                    ->visible(fn (Get $get): bool => (bool) $get('is_working_day'))
                    ->schema([
                        MenuSectionsRepeater::make(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('day_index')
            ->columns([
                TextColumn::make('day_index')
                    ->label('День')
                    ->formatStateUsing(fn (int $state, MenuTemplateDay $record): string => static::dayLabel($state, $record))
                    ->sortable(),

                IconColumn::make('is_working_day')
                    ->label('Робочий')
                    ->boolean(),

                TextColumn::make('sections_count')
                    ->label('Секцій')
                    ->counts('sections')
                    ->alignCenter(),
            ])
            ->defaultSort('day_index')
            ->paginated(false)
            ->recordActions([
                EditAction::make()->label('Заповнити'),
            ]);
    }

    private static function dayLabel(int $dayIndex, MenuTemplateDay $record): string
    {
        $weekdays = [1 => 'Понеділок', 'Вівторок', 'Середа', 'Четвер', 'П\'ятниця', 'Субота', 'Неділя'];

        $weekday = $weekdays[(($dayIndex - 1) % 7) + 1];

        if ($record->template?->cycle_length > 7) {
            $week = (int) floor(($dayIndex - 1) / 7) + 1;

            return "{$weekday} · тиждень {$week}";
        }

        return $weekday;
    }
}

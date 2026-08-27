<?php

namespace App\Filament\Resources\NonWorkingDays;

use App\Filament\Resources\NonWorkingDays\Pages\CreateNonWorkingDay;
use App\Filament\Resources\NonWorkingDays\Pages\EditNonWorkingDay;
use App\Filament\Resources\NonWorkingDays\Pages\ListNonWorkingDays;
use App\Filament\Resources\NonWorkingDays\Schemas\NonWorkingDayForm;
use App\Filament\Resources\NonWorkingDays\Tables\NonWorkingDaysTable;
use App\Models\NonWorkingDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class NonWorkingDayResource extends Resource
{
    protected static ?string $model = NonWorkingDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $navigationLabel = 'Неробочі дні';

    protected static ?string $modelLabel = 'неробочий день';

    protected static ?string $pluralModelLabel = 'неробочі дні';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return NonWorkingDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NonWorkingDaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNonWorkingDays::route('/'),
            'create' => CreateNonWorkingDay::route('/create'),
            'edit' => EditNonWorkingDay::route('/{record}/edit'),
        ];
    }
}

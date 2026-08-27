<?php

namespace App\Filament\Resources\NonWorkingDays\Pages;

use App\Filament\Resources\NonWorkingDays\NonWorkingDayResource;
use App\Filament\Resources\NonWorkingDays\Tables\NonWorkingDaysTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNonWorkingDays extends ListRecords
{
    protected static string $resource = NonWorkingDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            NonWorkingDaysTable::addRangeAction(),
            CreateAction::make()->label('Додати день'),
        ];
    }
}

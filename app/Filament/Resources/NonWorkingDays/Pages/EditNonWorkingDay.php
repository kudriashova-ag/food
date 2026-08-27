<?php

namespace App\Filament\Resources\NonWorkingDays\Pages;

use App\Filament\Resources\NonWorkingDays\NonWorkingDayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNonWorkingDay extends EditRecord
{
    protected static string $resource = NonWorkingDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

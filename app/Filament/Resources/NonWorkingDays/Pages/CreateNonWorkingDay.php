<?php

namespace App\Filament\Resources\NonWorkingDays\Pages;

use App\Filament\Resources\NonWorkingDays\NonWorkingDayResource;
use App\Models\NonWorkingDay;
use App\Services\Menu\HolidayRangeService;
use Filament\Resources\Pages\CreateRecord;

class CreateNonWorkingDay extends CreateRecord
{
    protected static string $resource = NonWorkingDayResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** Наявне меню на цю дату теж треба закрити, як і при додаванні періоду. */
    protected function afterCreate(): void
    {
        /** @var NonWorkingDay $day */
        $day = $this->record;

        app(HolidayRangeService::class)->createRange(
            from: $day->date,
            to: $day->date,
            title: $day->title,
            actor: auth()->user(),
        );
    }
}

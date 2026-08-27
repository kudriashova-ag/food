<?php

namespace App\Filament\Supplier\Resources\MenuDays\Pages;

use App\Filament\Supplier\Resources\MenuDays\MenuDayResource;
use App\Services\Menu\MenuTemplateService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMenuDays extends ListRecords
{
    protected static string $resource = MenuDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->copyWeekAction(),
            CreateAction::make(),
        ];
    }

    private function copyWeekAction(): Action
    {
        return Action::make('copyWeek')
            ->label('Скопіювати тиждень')
            ->icon('heroicon-o-document-duplicate')
            ->modalHeading('Копіювання тижня')
            ->modalDescription('Дати вирівнюються по понеділку того тижня, у який вони потрапляють.')
            ->modalSubmitActionLabel('Скопіювати')
            ->schema([
                DatePicker::make('source')
                    ->label('Який тиждень копіюємо')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->default(now()->startOfWeek()),

                DatePicker::make('target')
                    ->label('На який тиждень')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->different('source')
                    ->default(now()->startOfWeek()->addWeek()),

                Toggle::make('overwrite')
                    ->label('Перезаписати наявні дні'),

                Toggle::make('publish')
                    ->label('Одразу опублікувати'),
            ])
            ->action(function (array $data): void {
                $result = app(MenuTemplateService::class)->copyWeek(
                    supplier: MenuDayResource::currentSupplierId(),
                    sourceWeekStart: $data['source'],
                    targetWeekStart: $data['target'],
                    overwrite: (bool) $data['overwrite'],
                    publish: (bool) $data['publish'],
                );

                Notification::make()
                    ->title('Тиждень скопійовано')
                    ->body($result->summary())
                    ->success()
                    ->send();
            });
    }
}

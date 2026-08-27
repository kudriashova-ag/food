<?php

namespace App\Filament\Supplier\Resources\MenuTemplates\Tables;

use App\Models\MenuTemplate;
use App\Services\Menu\MenuTemplateService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MenuTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cycle_length')
                    ->label('Цикл')
                    ->formatStateUsing(fn (int $state): string => $state === 14 ? 'Два тижні' : 'Тиждень'),

                TextColumn::make('days_count')
                    ->label('Днів заповнено')
                    ->counts(['days' => fn ($query) => $query->has('sections')])
                    ->alignCenter(),
            ])
            ->recordActions([
                static::applyAction(),
                EditAction::make()->label('Заповнити дні'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Шаблонів ще немає')
            ->emptyStateDescription('Шаблон економить головне: меню на місяць складається за кілька натискань, а не заповнюється щодня.');
    }

    private static function applyAction(): Action
    {
        return Action::make('apply')
            ->label('Застосувати')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Застосувати шаблон до діапазону дат')
            ->modalSubmitActionLabel('Застосувати')
            ->schema([
                DatePicker::make('from')
                    ->label('З дати')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->default(now()->startOfWeek()->addWeek()),

                DatePicker::make('to')
                    ->label('По дату')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->afterOrEqual('from')
                    ->default(now()->startOfWeek()->addWeek()->addDays(4)),

                Toggle::make('overwrite')
                    ->label('Перезаписати наявні дні')
                    ->helperText('Вимкнено — дні, де меню вже є, лишаються недоторканими.'),

                Toggle::make('publish')
                    ->label('Одразу опублікувати')
                    ->helperText('Вимкнено — дні створяться чернетками.'),
            ])
            ->action(function (MenuTemplate $record, array $data): void {
                $result = app(MenuTemplateService::class)->apply(
                    template: $record,
                    from: $data['from'],
                    to: $data['to'],
                    overwrite: (bool) $data['overwrite'],
                    publish: (bool) $data['publish'],
                );

                Notification::make()
                    ->title('Шаблон застосовано')
                    ->body($result->summary())
                    ->success()
                    ->send();
            });
    }
}

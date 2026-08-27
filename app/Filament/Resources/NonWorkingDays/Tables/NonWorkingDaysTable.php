<?php

namespace App\Filament\Resources\NonWorkingDays\Tables;

use App\Models\NonWorkingDay;
use App\Services\Menu\HolidayRangeService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NonWorkingDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Дата')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('D, d.m.Y'))
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Причина')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('author.name')
                    ->label('Додав')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('date')
            ->filters([
                Filter::make('upcoming')
                    ->label('Від сьогодні')
                    ->query(fn (Builder $query): Builder => $query->whereDate('date', '>=', today()))
                    ->default(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Неробочих днів немає')
            ->emptyStateDescription('Додайте свята й канікули — у ці дати меню не показується учням, а шаблони створюють день порожнім.');
    }

    /** Канікули зручніше задати діапазоном, ніж по одному дню. */
    public static function addRangeAction(): Action
    {
        return Action::make('addRange')
            ->label('Додати період')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Канікули або святковий період')
            ->modalSubmitActionLabel('Додати')
            ->schema([
                DatePicker::make('from')
                    ->label('З дати')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y'),

                DatePicker::make('to')
                    ->label('По дату')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->afterOrEqual('from'),

                TextInput::make('title')
                    ->label('Причина')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Зимові канікули'),

                Toggle::make('skip_weekends')
                    ->label('Пропустити суботи й неділі')
                    ->default(true)
                    ->helperText('Вихідні зазвичай і так неробочі — немає сенсу засмічувати ними календар.'),

                Toggle::make('close_published_days')
                    ->label('Закрити вже створені меню на ці дати')
                    ->default(true)
                    ->helperText('Інакше меню лишиться в кабінеті постачальника як робоче.'),
            ])
            ->action(function (array $data): void {
                $service = app(HolidayRangeService::class);

                $result = $service->createRange(
                    from: $data['from'],
                    to: $data['to'],
                    title: $data['title'],
                    actor: auth()->user(),
                    skipWeekends: (bool) $data['skip_weekends'],
                    closePublishedDays: (bool) $data['close_published_days'],
                );

                Notification::make()
                    ->title('Період додано')
                    ->body($service->summary($result))
                    ->success()
                    ->send();
            });
    }
}

<?php

namespace App\Filament\Resources\SchoolClasses\Tables;

use App\Models\SchoolClass;
use App\Services\School\AcademicYearService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SchoolClassesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Клас')
                    ->sortable(['grade', 'letter']),

                TextColumn::make('academic_year')
                    ->label('Навчальний рік')
                    ->formatStateUsing(fn (int $state): string => $state.'/'.substr((string) ($state + 1), 2))
                    ->sortable(),

                TextColumn::make('students_count')
                    ->label('Учнів')
                    ->counts('students')
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('grade')
            ->filters([
                SelectFilter::make('academic_year')
                    ->label('Навчальний рік')
                    ->options(fn (): array => SchoolClass::query()
                        ->distinct()
                        ->orderByDesc('academic_year')
                        ->pluck('academic_year', 'academic_year')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Класів немає')
            ->emptyStateDescription('Класи створюються тут або автоматично під час імпорту учнів.');
    }

    /** Викликається зі сторінки списку — дія стосується всієї школи, а не рядка. */
    public static function promoteAction(): Action
    {
        return Action::make('promote')
            ->label('Перевести на новий рік')
            ->icon('heroicon-o-arrow-up-right')
            ->color('warning')
            ->modalHeading('Переведення на наступний навчальний рік')
            ->modalDescription('1-А стане 2-А, одинадцятикласники будуть деактивовані. Історія замовлень зберігається.')
            ->modalSubmitActionLabel('Перевести')
            ->requiresConfirmation()
            ->schema([
                Select::make('from_year')
                    ->label('Який рік завершуємо')
                    ->options(fn (): array => SchoolClass::query()
                        ->distinct()
                        ->orderByDesc('academic_year')
                        ->pluck('academic_year', 'academic_year')
                        ->all())
                    ->default(fn (): ?int => SchoolClass::query()->max('academic_year'))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $result = app(AcademicYearService::class)->promote((int) $data['from_year']);

                Notification::make()
                    ->title('Переведення виконано')
                    ->body("Переведено учнів: {$result['promoted']}, випущено: {$result['graduated']}, створено класів: {$result['classes']}.")
                    ->success()
                    ->send();
            });
    }
}

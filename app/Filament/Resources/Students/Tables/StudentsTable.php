<?php

namespace App\Filament\Resources\Students\Tables;

use App\Models\SchoolClass;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('ПІБ')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('schoolClass.title')
                    ->label('Клас')
                    ->sortable(),

                TextColumn::make('user.login')
                    ->label('Логін')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('user.email')
                    ->label('E-mail')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('consent_at')
                    ->label('Згода')
                    ->boolean()
                    ->tooltip(fn (Student $record): string => $record->consent_at
                        ? 'Надана '.$record->consent_at->translatedFormat('d.m.Y H:i')
                        : 'Ще не надана'),

                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('full_name')
            ->filters([
                SelectFilter::make('school_class_id')
                    ->label('Клас')
                    ->options(fn (): array => SchoolClass::query()
                        ->orderBy('grade')->orderBy('letter')
                        ->get()
                        ->mapWithKeys(fn (SchoolClass $class): array => [$class->id => $class->title])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label('Активність')
                    ->placeholder('Усі')
                    ->trueLabel('Тільки активні')
                    ->falseLabel('Тільки деактивовані')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                static::resetPasswordAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::deactivateBulkAction(),
                    static::activateBulkAction(),
                ]),
            ])
            ->emptyStateHeading('Учнів немає')
            ->emptyStateDescription('Імпортуйте список зі школи або додайте учня вручну.');
    }

    private static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Скинути пароль')
            ->icon('heroicon-o-key')
            ->modalHeading('Новий пароль')
            ->modalSubmitActionLabel('Зберегти')
            ->schema([
                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(6)
                    ->default(fn (): string => Str::password(8, symbols: false))
                    ->helperText('Передайте класному керівнику — після закриття вікна пароль не відновити.'),
            ])
            ->action(function (Student $record, array $data): void {
                $record->user?->update(['password' => Hash::make($data['password'])]);

                Notification::make()->title('Пароль оновлено')->success()->send();
            });
    }

    private static function deactivateBulkAction(): BulkAction
    {
        return BulkAction::make('deactivate')
            ->label('Деактивувати')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Учні не зможуть увійти. Їхні замовлення лишаються у звітах і журналі.')
            ->action(function (Collection $records): void {
                foreach ($records as $student) {
                    $student->update(['is_active' => false]);
                    $student->user?->update(['is_active' => false]);
                }

                Notification::make()->title('Деактивовано')->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function activateBulkAction(): BulkAction
    {
        return BulkAction::make('activate')
            ->label('Активувати')
            ->icon('heroicon-o-lock-open')
            ->action(function (Collection $records): void {
                foreach ($records as $student) {
                    $student->update(['is_active' => true]);
                    $student->user?->update(['is_active' => true]);
                }

                Notification::make()->title('Активовано')->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}

<?php

namespace App\Filament\Resources\LoginAttempts;

use App\Filament\Resources\LoginAttempts\Pages\ListLoginAttempts;
use App\Models\LoginAttempt;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Журнал входів на сайт: хто, коли, з якого пристрою, і для невдалих спроб —
 * що саме було введено як пароль. Тільки перегляд; старі записи видаляються самі.
 */
class LoginAttemptResource extends Resource
{
    protected static ?string $model = LoginAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightEndOnRectangle;

    protected static ?string $navigationLabel = 'Журнал входів';

    protected static ?string $modelLabel = 'спроба входу';

    protected static ?string $pluralModelLabel = 'журнал входів';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Коли')
                    ->formatStateUsing(fn ($state): string => $state->translatedFormat('d.m.Y H:i:s'))
                    ->sortable(),

                IconColumn::make('successful')
                    ->label('Успішно')
                    ->boolean(),

                TextColumn::make('login')
                    ->label('Логін')
                    ->formatStateUsing(fn (?string $state): string => static::visible($state))
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('password')
                    ->label('Введений пароль')
                    ->state(fn (LoginAttempt $record): ?string => $record->password)
                    ->formatStateUsing(fn (?string $state): string => static::visible($state))
                    ->placeholder('—')
                    ->fontFamily('mono'),

                TextColumn::make('user.name')
                    ->label('Користувач')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('device')
                    ->label('Пристрій')
                    ->state(fn (LoginAttempt $record): string => $record->device())
                    ->tooltip(fn (LoginAttempt $record): ?string => $record->user_agent),

                TextColumn::make('ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('successful')
                    ->label('Результат')
                    ->trueLabel('Успішні')
                    ->falseLabel('Невдалі'),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Дата з')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Дата по')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Спроб входу ще не було')
            ->emptyStateDescription('Записи зберігаються '.LoginAttempt::PRUNE_DAYS.' днів.');
    }

    /**
     * Пробіли й переноси робимо видимими (␣, ↵), а в кінці — довжина:
     * так одразу видно зайвий пробіл від автозаповнення телефона.
     */
    public static function visible(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return strtr($value, [' ' => '␣', "\n" => '↵', "\r" => '', "\t" => '⇥'])
            .' ('.mb_strlen($value).')';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoginAttempts::route('/'),
        ];
    }
}

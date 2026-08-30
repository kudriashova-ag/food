<?php

namespace App\Filament\Supplier\Schemas;

use App\Enums\MenuSectionType;
use App\Models\Dish;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Один і той самий конструктор секцій для меню конкретного дня і для дня шаблону —
 * щоб постачальник не вчив два різні інтерфейси.
 */
class MenuSectionsRepeater
{
    /** ТЗ, п. 5.2: комплексів на день — від 0 до 3. */
    public const MAX_COMPLEXES = 3;

    public static function make(string $name = 'sections'): Repeater
    {
        return Repeater::make($name)
            ->hiddenLabel()
            ->relationship()
            ->orderColumn('sort')
            ->addActionLabel('Додати секцію')
            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
            ->collapsible()
            ->cloneable()
            ->defaultItems(0)
            ->rule(static::complexLimitRule())
            ->schema([
                Select::make('type')
                    ->label('Тип секції')
                    ->options(collect(MenuSectionType::cases())
                        ->mapWithKeys(fn (MenuSectionType $type): array => [$type->value => $type->label()])
                        ->all())
                    ->default(MenuSectionType::Complex->value)
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): string => match ($get('type')) {
                        MenuSectionType::Choice->value => 'Учень обере один варіант або пропустить секцію.',
                        MenuSectionType::Extra->value => 'Учень обирає незалежно й у довільній кількості.',
                        default => 'Учень купує весь комплекс цілком за фіксованою ціною. Склад страв — інформаційний, вибору немає.',
                    }),

                TextInput::make('title')
                    ->label('Назва секції')
                    ->maxLength(255)
                    ->placeholder(fn (Get $get): string => static::defaultTitleFor($get('type')))
                    ->helperText('Не заповните — підставимо назву за типом секції.')
                    // Порожнє поле неприпустиме в БД (NOT NULL) — підставляємо
                    // ту саму назву, що й у placeholder, тільки-но форма зберігається.
                    ->dehydrateStateUsing(fn (Get $get, ?string $state): string => filled($state)
                        ? $state
                        : static::defaultTitleFor($get('type'))),

                TextInput::make('price')
                    ->label('Ціна комплексу')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->suffix('грн')
                    ->required(fn (Get $get): bool => $get('type') === MenuSectionType::Complex->value)
                    ->visible(fn (Get $get): bool => $get('type') === MenuSectionType::Complex->value)
                    ->live(onBlur: true)
                    ->helperText('Фіксована ціна за весь комплекс. Учень купує комплекс тільки цілком.'),

                Repeater::make('sectionDishes')
                    ->label('Страви')
                    ->relationship()
                    ->orderColumn('sort')
                    ->addActionLabel('Додати страву')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->columns(1)
                    ->schema([
                        Select::make('dish_id')
                            ->hiddenLabel()
                            ->options(fn (): array => static::dishOptions())
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    ]),
            ]);
    }

    /** Назва секції за замовчуванням, коли постачальник лишив поле порожнім. */
    private static function defaultTitleFor(?string $type): string
    {
        return match ($type) {
            MenuSectionType::Choice->value => 'Перша страва',
            MenuSectionType::Extra->value => 'Додатково',
            default => 'Комплекс №1',
        };
    }

    /** @return array<int, string> */
    private static function dishOptions(): array
    {
        return Dish::query()
            ->where('supplier_id', auth()->user()?->supplier_id)
            ->available()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Dish $dish): array => [
                $dish->id => sprintf('%s — %s грн', $dish->name, rtrim(rtrim((string) $dish->price, '0'), '.')),
            ])
            ->all();
    }

    private static function complexLimitRule(): Closure
    {
        // Filament спершу обчислює зовнішнє замикання, щоб отримати саме правило.
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            $complexes = collect($value)
                ->filter(fn (array $section): bool => ($section['type'] ?? null) === MenuSectionType::Complex->value)
                ->count();

            if ($complexes > self::MAX_COMPLEXES) {
                $fail('Комплексів на день може бути не більше '.self::MAX_COMPLEXES.'.');
            }
        };
    }
}

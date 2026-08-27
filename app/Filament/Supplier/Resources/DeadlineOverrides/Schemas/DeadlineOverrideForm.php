<?php

namespace App\Filament\Supplier\Resources\DeadlineOverrides\Schemas;

use App\Models\DeadlineOverride;
use App\Services\Deadlines\DeadlineService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DeadlineOverrideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Виняток на конкретну дату')
                    ->description('Загальне правило дня тижня не змінюється — виняток діє лише на цю дату.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('date')
                            ->label('Дата харчування')
                            ->required()
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                // Підставляємо поточні дедлайни за правилом — далі їх можна посунути.
                                $deadlines = app(DeadlineService::class)
                                    ->for(auth()->user()?->supplier_id, $state);

                                $set('order_deadline_at', $deadlines->orderAt?->format('Y-m-d H:i:s'));
                                $set('cancel_deadline_at', $deadlines->cancelAt?->format('Y-m-d H:i:s'));
                            })
                            ->rule(fn (?DeadlineOverride $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $exists = DeadlineOverride::query()
                                    ->where('supplier_id', auth()->user()?->supplier_id)
                                    ->whereDate('date', $value)
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('Виняток на цю дату вже є — відредагуйте наявний.');
                                }
                            })
                            ->columnSpanFull(),

                        DateTimePicker::make('order_deadline_at')
                            ->label('Приймання замовлень до')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d.m.Y H:i')
                            ->helperText('Порожньо — діє загальне правило.'),

                        DateTimePicker::make('cancel_deadline_at')
                            ->label('Скасування до')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d.m.Y H:i')
                            ->helperText('Порожньо — діє загальне правило.')
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $orderDeadline = $get('order_deadline_at');

                                if (blank($value) || blank($orderDeadline)) {
                                    return;
                                }

                                if (strtotime((string) $value) < strtotime((string) $orderDeadline)) {
                                    $fail('Скасування не може закриватися раніше за замовлення.');
                                }
                            }),

                        TextInput::make('reason')
                            ->label('Причина')
                            ->placeholder('Перед святом')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

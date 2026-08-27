<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Models\SchoolClass;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Учень')
                    ->columns(2)
                    ->schema([
                        TextInput::make('full_name')
                            ->label('ПІБ')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('school_class_id')
                            ->label('Клас')
                            ->options(fn (): array => SchoolClass::query()
                                ->where('is_active', true)
                                ->orderBy('grade')->orderBy('letter')
                                ->get()
                                ->mapWithKeys(fn (SchoolClass $class): array => [$class->id => $class->title])
                                ->all())
                            ->searchable()
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true)
                            ->helperText('Деактивований учень не входить, але його замовлення лишаються у звітах.'),

                        Textarea::make('notes')
                            ->label('Примітки')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Доступ')
                    ->columns(2)
                    ->schema([
                        TextInput::make('login')
                            ->label('Логін')
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'users', column: 'login', ignoreRecord: false,
                                modifyRuleUsing: fn ($rule, $livewire) => $rule->ignore(
                                    $livewire->record?->user_id,
                                ))
                            ->helperText('Видається учневі. Змінювати без потреби не варто — за ним звіряється імпорт.'),

                        TextInput::make('email')
                            ->label('E-mail для сповіщень')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Опційно. Без нього пароль скидає адміністратор вручну.'),

                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->minLength(6)
                            ->default(fn (): string => Str::password(8, symbols: false))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? 'Запишіть пароль — потім він не показується.'
                                : 'Заповніть, щоб задати новий пароль.'),
                    ]),
            ]);
    }
}

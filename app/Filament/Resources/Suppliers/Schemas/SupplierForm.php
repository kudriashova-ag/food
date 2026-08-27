<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\UserRole;
use App\Models\Supplier;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Постачальник')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set, ?Supplier $record): void {
                                if ($record === null && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Адреса сторінки')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Латиницею — потрапляє в посилання.'),

                        FileUpload::make('logo_path')
                            ->label('Логотип')
                            ->image()
                            ->disk('public')
                            ->directory('suppliers')
                            ->maxSize(4096)
                            ->imageEditor(),

                        Toggle::make('is_visible')
                            ->label('Показувати учням')
                            ->default(true)
                            ->helperText('Прихований постачальник зникає зі списку, але його історія лишається у звітах.'),

                        Textarea::make('description')
                            ->label('Короткий опис')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Контакти (внутрішня інформація)')
                    ->columns(2)
                    ->schema([
                        TextInput::make('contact_person')->label('Контактна особа')->maxLength(255),
                        TextInput::make('phone')->label('Телефон')->tel()->maxLength(32),
                    ]),

                Section::make('Обліковий запис для входу')
                    ->description('Створюється разом із постачальником. Пароль можна скинути в будь-який момент.')
                    ->columns(2)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('account_email')
                            ->label('E-mail для входу')
                            ->email()
                            ->required()
                            ->unique(table: 'users', column: 'email')
                            ->dehydrated(),

                        TextInput::make('account_password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->default(fn (): string => Str::password(10, symbols: false))
                            ->helperText('Запишіть його — далі пароль не показується.')
                            ->dehydrated(),
                    ]),
            ]);
    }

    /** Роль облікового запису постачальника — щоб не дублювати рядок у сторінках. */
    public static function accountRole(): UserRole
    {
        return UserRole::Supplier;
    }
}

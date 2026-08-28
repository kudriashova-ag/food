<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SchoolSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.school-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Налаштування';

    protected static ?string $title = 'Налаштування школи';

    protected static ?int $navigationSort = 8;

    /** Ключі налаштувань і значення за замовчуванням. */
    public const DEFAULTS = [
        'school_name' => '',
        'school_address' => '',
        'school_contacts' => '',
        'privacy_policy_text' => 'Повний текст політики обробки персональних даних надає адміністрація школи.',
        'notification_signature' => 'Шкільна їдальня',
        'deadline_reminder_hours' => '3',
        'deadline_reminder_enabled' => '1',
        'cancel_reminder_enabled' => '0',
        'support_email' => '',
        'support_telegram_chat_id' => '',
        'service_info_text' => '',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $state = [];

        foreach (self::DEFAULTS as $key => $default) {
            $state[$key] = Setting::get($key, $default);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Реквізити школи')
                    ->columns(2)
                    ->schema([
                        TextInput::make('school_name')->label('Назва школи')->maxLength(255),
                        TextInput::make('school_contacts')->label('Контакти')->maxLength(255),
                        Textarea::make('school_address')->label('Адреса')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('Обробка персональних даних')
                    ->description('Текст показується учневі при першому вході, перед наданням згоди.')
                    ->schema([
                        Textarea::make('privacy_policy_text')
                            ->label('Текст для сторінки згоди')
                            ->rows(4),
                    ]),

                Section::make('Сповіщення')
                    ->columns(2)
                    ->schema([
                        TextInput::make('notification_signature')
                            ->label('Підпис у листах')
                            ->maxLength(255),

                        TextInput::make('deadline_reminder_hours')
                            ->label('Нагадувати за скільки годин до закриття')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(48),

                        Toggle::make('deadline_reminder_enabled')
                            ->label('Нагадувати про дедлайн замовлення'),

                        Toggle::make('cancel_reminder_enabled')
                            ->label('Нагадувати про дедлайн скасування'),
                    ]),

                Section::make('Звернення з сайту')
                    ->description('Куди надсилати питання з форми «Допомога». Порожні поля означають, що канал вимкнено.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('support_email')
                            ->label('Email для звернень')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Без нього листи не надсилаються.'),

                        TextInput::make('support_telegram_chat_id')
                            ->label('Telegram chat ID адміністратора')
                            ->maxLength(64)
                            ->helperText('Дізнатися свій ID можна в бота @userinfobot.'),
                    ]),

                Section::make('Інформація про сервіс')
                    ->description('Текст на сторінці «Інформація про сервіс». Порожнє поле — показується стандартний опис.')
                    ->schema([
                        Textarea::make('service_info_text')
                            ->label('Довільний текст від школи')
                            ->rows(4)
                            ->helperText('Додається до загального опису — сюди зручно вписати особливості вашої школи.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (self::DEFAULTS as $key => $default) {
            Setting::put($key, (string) ($state[$key] ?? $default));
        }

        Notification::make()->title('Налаштування збережено')->success()->send();
    }
}

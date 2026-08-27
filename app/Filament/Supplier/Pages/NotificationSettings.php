<?php

namespace App\Filament\Supplier\Pages;

use App\Models\Supplier;
use App\Models\TelegramLink;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class NotificationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.supplier.pages.notification-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Сповіщення';

    protected static ?string $title = 'Сповіщення про замовлення';

    protected static ?int $navigationSort = 9;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $telegramLink = null;

    public function mount(): void
    {
        $supplier = $this->supplier();

        $this->form->fill([
            'digest_enabled' => $supplier->digest_enabled,
            'digest_time' => substr((string) $supplier->digest_time, 0, 5),
            'report_emails' => $supplier->report_emails,
            'cancellation_alerts_enabled' => $supplier->cancellation_alerts_enabled,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Вечірнє зведення на завтра')
                    ->description('Надсилається на пошту й у Telegram. До листа додається файл Excel зі списком по класах.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('digest_enabled')
                            ->label('Надсилати зведення')
                            ->live()
                            ->columnSpanFull(),

                        TimePicker::make('digest_time')
                            ->label('Час надсилання')
                            ->seconds(false)
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('digest_enabled'))
                            ->helperText('Якщо на цей час приймання замовлень ще триває, після його закриття прийде уточнене зведення.'),

                        TextInput::make('report_emails')
                            ->label('E-mail для звітів')
                            ->placeholder('kuhnya@example.com, menedzher@example.com')
                            ->helperText('Кілька адрес — через кому. Порожньо — надсилаємо на пошту вашого облікового запису.')
                            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $email) {
                                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                        $fail("Некоректна адреса: «{$email}».");
                                    }
                                }
                            }),
                    ]),

                Section::make('Скасування')
                    ->schema([
                        Toggle::make('cancellation_alerts_enabled')
                            ->label('Повідомляти про скасування після зведення')
                            ->helperText('Скасування до зведення просто враховуються в його цифрах — окреме повідомлення не йде.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $this->supplier()->update([
            'digest_enabled' => (bool) $state['digest_enabled'],
            'digest_time' => $state['digest_time'] ?: '18:00',
            'report_emails' => $state['report_emails'] ?: null,
            'cancellation_alerts_enabled' => (bool) $state['cancellation_alerts_enabled'],
        ]);

        Notification::make()->title('Налаштування збережено')->success()->send();
    }

    /** @return Collection<int, TelegramLink> */
    public function getTelegramLinks(): Collection
    {
        return $this->supplier()->telegramLinks()->orderBy('linked_at')->get();
    }

    public function getRecipients(): array
    {
        return $this->supplier()->reportRecipients();
    }

    public function isTelegramAvailable(): bool
    {
        return app(TelegramClient::class)->isConfigured();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectTelegram')
                ->label('Підключити Telegram')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn (): bool => $this->isTelegramAvailable())
                ->action(function (): void {
                    $this->telegramLink = app(TelegramLinkService::class)->deepLinkFor($this->supplier());

                    Notification::make()
                        ->title('Посилання створено')
                        ->body('Відкрийте його в Telegram протягом 15 хвилин.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function disconnectTelegram(int $linkId): void
    {
        $link = $this->supplier()->telegramLinks()->whereKey($linkId)->first();

        if ($link !== null) {
            app(TelegramLinkService::class)->disconnect($link);

            Notification::make()->title('Telegram відключено')->success()->send();
        }
    }

    private function supplier(): Supplier
    {
        return auth()->user()->supplier;
    }
}

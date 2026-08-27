<?php

namespace App\Filament\Supplier\Pages;

use App\Models\DeadlineRule;
use App\Services\Deadlines\DeadlineService;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Правила дедлайнів постачальника — одна форма на всі сім днів тижня.
 * Зсув задається у відносному вигляді: «того ж дня», «за день до» тощо.
 */
class ManageDeadlines extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.supplier.pages.manage-deadlines';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Дедлайни';

    protected static ?string $title = 'Дедлайни замовлення та скасування';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    private const WEEKDAYS = [
        1 => 'Понеділок',
        2 => 'Вівторок',
        3 => 'Середа',
        4 => 'Четвер',
        5 => 'П\'ятниця',
        6 => 'Субота',
        7 => 'Неділя',
    ];

    private const OFFSETS = [
        0 => 'того ж дня',
        1 => 'за день до',
        2 => 'за 2 дні до',
        3 => 'за 3 дні до',
        7 => 'за тиждень до',
    ];

    public function mount(): void
    {
        $rules = DeadlineRule::query()
            ->where('supplier_id', $this->supplierId())
            ->get()
            ->keyBy('weekday');

        $state = [];

        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $rule = $rules->get($weekday);

            $state[$weekday] = [
                'enabled' => $rule !== null,
                'order_offset_days' => $rule?->order_offset_days ?? 1,
                'order_time' => $rule?->order_time ?? '09:00',
                'cancel_offset_days' => $rule?->cancel_offset_days ?? 1,
                'cancel_time' => $rule?->cancel_time ?? '09:00',
            ];
        }

        $this->form->fill(['rules' => $state]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Правила по днях тижня')
                    ->description('Дедлайн скасування може бути пізнішим за дедлайн замовлення, але не раніше.')
                    ->schema(collect(self::WEEKDAYS)
                        ->map(fn (string $label, int $weekday) => static::weekdayRow($weekday, $label))
                        ->values()
                        ->all()),
            ])
            ->statePath('data');
    }

    private static function weekdayRow(int $weekday, string $label): Grid
    {
        $path = "rules.{$weekday}";

        return Grid::make(5)
            ->schema([
                Toggle::make("{$path}.enabled")
                    ->label($label)
                    ->live()
                    ->helperText('Приймання замовлень'),

                Select::make("{$path}.order_offset_days")
                    ->label('Замовлення')
                    ->options(self::OFFSETS)
                    ->required()
                    ->visible(fn (Get $get): bool => (bool) $get("{$path}.enabled")),

                TimePicker::make("{$path}.order_time")
                    ->label('до')
                    ->seconds(false)
                    ->required()
                    ->visible(fn (Get $get): bool => (bool) $get("{$path}.enabled")),

                Select::make("{$path}.cancel_offset_days")
                    ->label('Скасування')
                    ->options(self::OFFSETS)
                    ->required()
                    ->visible(fn (Get $get): bool => (bool) $get("{$path}.enabled")),

                TimePicker::make("{$path}.cancel_time")
                    ->label('до')
                    ->seconds(false)
                    ->required()
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $weekday, $label): void {
                        $candidate = new DeadlineRule([
                            'order_offset_days' => (int) $get("rules.{$weekday}.order_offset_days"),
                            'order_time' => $get("rules.{$weekday}.order_time"),
                            'cancel_offset_days' => (int) $get("rules.{$weekday}.cancel_offset_days"),
                            'cancel_time' => $value,
                        ]);

                        if (! $candidate->cancelIsNotEarlierThanOrder()) {
                            $fail("{$label}: скасування не може закриватися раніше за замовлення.");
                        }
                    })
                    ->visible(fn (Get $get): bool => (bool) $get("{$path}.enabled")),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState()['rules'] ?? [];
        $supplierId = $this->supplierId();

        DB::transaction(function () use ($state, $supplierId): void {
            foreach ($state as $weekday => $row) {
                if (! ($row['enabled'] ?? false)) {
                    DeadlineRule::query()
                        ->where('supplier_id', $supplierId)
                        ->where('weekday', $weekday)
                        ->delete();

                    continue;
                }

                DeadlineRule::query()->updateOrCreate(
                    ['supplier_id' => $supplierId, 'weekday' => $weekday],
                    [
                        'order_offset_days' => (int) $row['order_offset_days'],
                        'order_time' => $row['order_time'],
                        'cancel_offset_days' => (int) $row['cancel_offset_days'],
                        'cancel_time' => $row['cancel_time'],
                    ],
                );
            }
        });

        // Правила змінилися — кеш сервісу в межах запиту треба скинути.
        app(DeadlineService::class)->forget($supplierId);

        Notification::make()
            ->title('Дедлайни збережено')
            ->success()
            ->send();
    }

    private function supplierId(): ?int
    {
        return auth()->user()?->supplier_id;
    }
}

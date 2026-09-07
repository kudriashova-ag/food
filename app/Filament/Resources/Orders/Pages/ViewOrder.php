<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Orders\CancellationService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

class ViewOrder extends ViewRecord implements HasActions
{
    use InteractsWithActions;

    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.resources.orders.pages.view-order';

    public function getTitle(): string
    {
        return 'Замовлення '.$this->getRecord()->number;
    }

    /**
     * Усі позиції замовлення (усіх постачальників), згруповані по днях харчування.
     *
     * @return Collection<string, Collection<int, OrderLine>>
     */
    public function getLinesByDate(): Collection
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return $order->lines()
            ->with('supplier')
            ->orderBy('service_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OrderLine $line): string => $line->service_date->toDateString());
    }

    public function getTotal(): float
    {
        return (float) $this->getRecord()->total_amount;
    }

    public function hasActiveLines(): bool
    {
        return $this->getRecord()->lines()->active()->exists();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->cancelOrderAction(),
        ];
    }

    /** Скасовує все замовлення цілком — усі активні позиції незалежно від постачальника чи дня. */
    public function cancelOrderAction(): Action
    {
        return Action::make('cancelOrder')
            ->label('Скасувати все замовлення')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->hasActiveLines())
            ->requiresConfirmation()
            ->modalHeading('Скасування всього замовлення')
            ->modalDescription('Усі активні позиції цього замовлення буде скасовано. Дію не можна відмінити.')
            ->modalSubmitActionLabel('Скасувати все')
            ->schema([
                Textarea::make('reason')
                    ->label('Причина')
                    ->required()
                    ->rows(2)
                    ->helperText('Причина зберігається в журналі та показується учневі.'),
            ])
            ->action(function (array $data): void {
                /** @var Order $order */
                $order = $this->getRecord();

                $count = app(CancellationService::class)->cancelOrder($order, auth()->user(), $data['reason']);

                Notification::make()
                    ->title($count > 0 ? "Скасовано позицій: {$count}" : 'Нічого скасовувати — усі позиції вже скасовані')
                    ->success()
                    ->send();
            });
    }

    /** Скасування однієї позиції — виклик з view: {{ $this->cancelLineAction(['record' => $line->id]) }}. */
    public function cancelLineAction(): Action
    {
        return Action::make('cancelLine')
            ->label('Скасувати')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->size('sm')
            ->arguments(['record' => null])
            ->visible(function (array $arguments): bool {
                $record = OrderLine::find($arguments['record'] ?? null);

                return $record !== null && ! $record->isCancelled();
            })
            ->modalHeading('Скасування позиції')
            ->modalSubmitActionLabel('Скасувати позицію')
            ->schema([
                Textarea::make('reason')
                    ->label('Причина')
                    ->required()
                    ->rows(2)
                    ->helperText('Причина зберігається в журналі та показується учневі.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $record = OrderLine::findOrFail($arguments['record']);

                app(CancellationService::class)->cancelLine(
                    $record,
                    auth()->user(),
                    reason: $data['reason'],
                    bypassDeadline: true,
                );

                Notification::make()->title('Позицію скасовано')->success()->send();
            });
    }
}

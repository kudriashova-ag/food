<?php

namespace App\Filament\Supplier\Resources\Orders\Pages;

use App\Filament\Supplier\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderLine;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.supplier.pages.view-order';

    public function getTitle(): string
    {
        return 'Замовлення '.$this->getRecord()->number;
    }

    /**
     * Позиції цього постачальника, згруповані по днях харчування.
     *
     * @return Collection<string, Collection<int, OrderLine>>
     */
    public function getLinesByDate(): Collection
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return $order->lines()
            ->where('supplier_id', OrderResource::currentSupplierId())
            ->orderBy('service_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OrderLine $line): string => $line->service_date->toDateString());
    }

    /** Підсумок — тільки за активними позиціями цього постачальника. */
    public function getTotal(): float
    {
        return $this->getLinesByDate()
            ->flatten()
            ->reject(fn (OrderLine $line): bool => $line->isCancelled())
            ->sum(fn (OrderLine $line): float => $line->subtotal());
    }
}

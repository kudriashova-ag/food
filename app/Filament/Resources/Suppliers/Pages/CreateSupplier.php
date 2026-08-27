<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    private ?string $accountEmail = null;

    private ?string $accountPassword = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->accountEmail = $data['account_email'] ?? null;
        $this->accountPassword = $data['account_password'] ?? null;

        unset($data['account_email'], $data['account_password']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->accountEmail === null) {
            return;
        }

        /** @var Supplier $supplier */
        $supplier = $this->record;

        User::create([
            'name' => $supplier->name,
            'email' => $this->accountEmail,
            'password' => $this->accountPassword,
            'role' => UserRole::Supplier,
            'supplier_id' => $supplier->id,
        ]);
    }
}

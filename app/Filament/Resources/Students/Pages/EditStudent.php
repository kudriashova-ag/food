<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Student $student */
        $student = $this->record;

        $data['login'] = $student->user?->login;
        $data['email'] = $student->user?->email;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Student $record */
        $record->user?->update(array_filter([
            'name' => $data['full_name'],
            'login' => $data['login'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ], fn (mixed $value, string $key): bool => $key === 'email' || $key === 'is_active' || filled($value), ARRAY_FILTER_USE_BOTH));

        $record->update([
            'full_name' => $data['full_name'],
            'school_class_id' => $data['school_class_id'],
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
        ]);

        return $record;
    }
}

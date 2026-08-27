<?php

namespace App\Filament\Resources\Students\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    /** Учень — це пара «користувач + картка», тому створюємо їх разом. */
    protected function handleRecordCreation(array $data): Model
    {
        $user = User::create([
            'name' => $data['full_name'],
            'login' => $data['login'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'role' => UserRole::Student,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $data['full_name'],
            'school_class_id' => $data['school_class_id'],
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}

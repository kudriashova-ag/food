<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Зміна власного пароля для адміністратора й постачальника — у їхніх кабінетах.
 * Учні й вчителі змінюють пароль на сторінці /settings.
 */
class ChangePasswordAction
{
    public static function make(): Action
    {
        return Action::make('changePassword')
            ->label('Змінити пароль')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalHeading('Зміна пароля')
            ->modalSubmitActionLabel('Змінити пароль')
            ->schema([
                TextInput::make('current_password')
                    ->label('Поточний пароль')
                    ->password()
                    ->revealable()
                    ->required()
                    ->currentPassword(guard: Filament::getAuthGuard())
                    ->validationMessages(['current_password' => 'Поточний пароль введено невірно.']),

                TextInput::make('password')
                    ->label('Новий пароль')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(6)
                    ->same('password_confirmation')
                    ->validationMessages(['same' => 'Паролі не збігаються.']),

                TextInput::make('password_confirmation')
                    ->label('Повторіть новий пароль')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $user = Filament::auth()->user();

                $user->update([
                    'password' => $data['password'],
                    'must_change_password' => false,
                ]);

                // Панелі перевіряють хеш пароля в сесії (AuthenticateSession):
                // без оновлення людину викинуло б із кабінету одразу після зміни.
                session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());

                Notification::make()->title('Пароль змінено')->success()->send();
            });
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\TelegramLink;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationSettingsController extends Controller
{
    public function show(Request $request, TelegramClient $client): View
    {
        $student = $request->user()->student;

        return view('notifications', [
            'student' => $student,
            'links' => $student->telegramLinks()->orderBy('linked_at')->get(),
            'telegramAvailable' => $client->isConfigured(),
        ]);
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ], attributes: ['email' => 'e-mail']);

        $request->user()->update(['email' => $data['email'] ?: null]);

        return back()->with('status', 'E-mail збережено.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'current_password.current_password' => 'Поточний пароль введено невірно.',
            'password.confirmed' => 'Паролі не збігаються.',
        ]);

        $request->user()->update(['password' => $data['password']]);

        return back()->with('status', 'Пароль змінено.');
    }

    /** Кнопка «Підключити Telegram» — видає одноразове посилання на 15 хвилин. */
    public function connectTelegram(Request $request, TelegramLinkService $links): RedirectResponse
    {
        $link = $links->deepLinkFor($request->user()->student);

        return back()->with('telegram_link', $link);
    }

    public function disconnectTelegram(Request $request, TelegramLink $link, TelegramLinkService $links): RedirectResponse
    {
        // Через (int): на деяких збірках PDO ключі приходять рядками,
        // і строге порівняння відмовляло у власній же прив'язці.
        $studentId = $request->user()->student?->id;

        abort_unless($studentId !== null && (int) $link->student_id === (int) $studentId, 403);

        $links->disconnect($link);

        return back()->with('status', 'Telegram відключено.');
    }
}

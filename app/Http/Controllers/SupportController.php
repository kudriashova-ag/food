<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SupportRequest;
use App\Services\Support\SupportRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupportController extends Controller
{
    /** Форма відкрита всім, тож обмежуємо частоту — інакше нею засиплють пошту. */
    private const MAX_PER_HOUR = 5;

    public function info(): View
    {
        return view('support.info', [
            'extra' => Setting::get('service_info_text'),
        ]);
    }

    public function show(Request $request): View
    {
        $student = $request->user()?->student;

        return view('support.help', [
            'name' => $student?->full_name ?? $request->user()?->name ?? '',
            'email' => $request->user()?->email ?? '',
        ]);
    }

    public function store(Request $request, SupportRequestService $support): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ], attributes: [
            'name' => "ім'я",
            'email' => 'email',
            'message' => 'питання',
        ]);

        $this->assertNotRateLimited($request);

        $supportRequest = SupportRequest::create([
            ...$data,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        $support->submit($supportRequest);

        RateLimiter::hit($this->throttleKey($request), 3600);

        return redirect()
            ->route('support.help')
            ->with('status', 'Питання надіслано. Відповідь надійде на вказану пошту.');
    }

    private function assertNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_PER_HOUR)) {
            return;
        }

        throw ValidationException::withMessages([
            'message' => 'Забагато звернень поспіль. Спробуйте за годину.',
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return 'support:'.$request->ip();
    }
}

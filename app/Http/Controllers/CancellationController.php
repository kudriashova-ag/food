<?php

namespace App\Http\Controllers;

use App\Exceptions\DeadlinePassedException;
use App\Models\OrderLine;
use App\Models\Supplier;
use App\Services\Orders\CancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CancellationController extends Controller
{
    public function __construct(private readonly CancellationService $cancellations) {}

    public function cancelLine(Request $request, OrderLine $line): RedirectResponse
    {
        $this->authorizeLine($request, $line);

        $quantity = $request->filled('quantity') ? (int) $request->input('quantity') : null;

        try {
            $this->cancellations->cancelLine($line, $request->user(), quantity: $quantity);
        } catch (DeadlinePassedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Скасовано: {$line->dish_name}.");
    }

    public function cancelDay(Request $request, Supplier $supplier, string $date): RedirectResponse
    {
        $student = $request->user()->student;

        try {
            $count = $this->cancellations->cancelDay($student, $supplier->id, $date, $request->user());
        } catch (DeadlinePassedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Скасовано позицій: {$count}.");
    }

    /**
     * Чужу позицію скасувати не можна.
     *
     * Порівнюємо через (int): залежно від збірки PDO ключі приходять то числами,
     * то рядками, і строге "3" === 3 давало 403 на власне ж замовлення.
     */
    private function authorizeLine(Request $request, OrderLine $line): void
    {
        $studentId = $request->user()->student?->id;

        abort_unless($studentId !== null && (int) $line->student_id === (int) $studentId, 403);
    }
}

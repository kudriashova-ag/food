<?php

namespace App\Console\Commands;

use App\Models\MenuDay;
use App\Models\NotificationLog;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Models\Student;
use App\Notifications\DeadlineReminder;
use App\Services\Deadlines\DeadlineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Нагадування про дедлайн замовлення (ТЗ, п. 12.3).
 *
 * Запускається за розкладом. За N годин до найранішого дедлайну дня
 * учні, які нічого не замовили на цю дату, отримують один лист.
 * Повторно той самий лист не надсилається — звірка за журналом відправок.
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'school:send-deadline-reminders';

    protected $description = 'Нагадати учням, які ще не замовили харчування, про наближення дедлайну';

    public function handle(DeadlineService $deadlines): int
    {
        if (Setting::get('deadline_reminder_enabled', '1') !== '1') {
            $this->info('Нагадування вимкнені в налаштуваннях.');

            return self::SUCCESS;
        }

        $hours = (int) Setting::get('deadline_reminder_hours', '3');
        $now = CarbonImmutable::now();
        $sent = 0;

        foreach ($this->upcomingDates() as $date) {
            $deadline = $this->earliestDeadline($deadlines, $date);

            if ($deadline === null || $now->greaterThanOrEqualTo($deadline)) {
                continue;
            }

            // Вікно: від «за N годин» до самого дедлайну.
            if ($now->lessThan($deadline->subHours($hours))) {
                continue;
            }

            $sent += $this->remindFor($date, $deadline);
        }

        $this->info("Надіслано нагадувань: {$sent}.");

        return self::SUCCESS;
    }

    /** @return array<int, CarbonImmutable> */
    private function upcomingDates(): array
    {
        $today = CarbonImmutable::today();
        $horizon = (int) config('school.menu_horizon_days');

        return MenuDay::query()
            ->visibleToStudents()
            ->whereDate('date', '>=', $today->toDateString())
            ->whereDate('date', '<=', $today->addDays($horizon)->toDateString())
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values()
            ->all();
    }

    /** Найраніший дедлайн серед постачальників, які опублікували меню на цю дату. */
    private function earliestDeadline(DeadlineService $deadlines, CarbonImmutable $date): ?CarbonImmutable
    {
        $supplierIds = MenuDay::query()
            ->visibleToStudents()
            ->whereDate('date', $date->toDateString())
            ->pluck('supplier_id');

        return $supplierIds
            ->map(fn (int $supplierId) => $deadlines->for($supplierId, $date)->orderAt)
            ->filter()
            ->sort()
            ->first();
    }

    private function remindFor(CarbonImmutable $date, CarbonImmutable $deadline): int
    {
        $orderedStudentIds = OrderLine::query()
            ->whereDate('service_date', $date->toDateString())
            ->active()
            ->distinct()
            ->pluck('student_id');

        $alreadyNotified = NotificationLog::query()
            ->where('event', 'deadline_reminder')
            ->whereJsonContains('payload->service_date', $date->toDateString())
            ->pluck('student_id');

        $students = Student::query()
            ->active()
            ->whereNotIn('id', $orderedStudentIds)
            ->whereNotIn('id', $alreadyNotified)
            ->with('user')
            ->get()
            ->filter(fn (Student $student): bool => $student->isNotifiable());

        foreach ($students as $student) {
            $student->user->notify(new DeadlineReminder($date, $deadline));
        }

        return $students->count();
    }
}

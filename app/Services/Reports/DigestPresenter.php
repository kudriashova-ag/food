<?php

namespace App\Services\Reports;

use App\Models\Supplier;
use App\Services\Deadlines\DeadlineService;
use Carbon\CarbonInterface;

/**
 * Один формат зведення на всі канали: лист, Telegram і відповідь на кнопку в боті.
 * Так кухня скрізь бачить однакові цифри в однаковому вигляді.
 */
class DigestPresenter
{
    public function __construct(
        private readonly KitchenReportService $reports,
        private readonly DeadlineService $deadlines,
    ) {}

    /**
     * @return array{
     *     dishes: \Illuminate\Support\Collection,
     *     positions: int,
     *     students: int,
     *     is_final: bool,
     *     order_deadline: ?CarbonInterface
     * }
     */
    public function data(Supplier $supplier, CarbonInterface $date): array
    {
        $summary = $this->reports->dailySummary($supplier, $date);
        $deadline = $this->deadlines->for($supplier, $date);

        return [
            ...$summary,
            // Остаточним зведення стає лише після закриття приймання.
            'is_final' => ! $deadline->orderingOpen(),
            'order_deadline' => $deadline->orderAt,
        ];
    }

    /** @return array<int, string> рядки для листа */
    public function lines(array $data, CarbonInterface $date): array
    {
        if ($data['positions'] === 0) {
            return ['Замовлень на цю дату немає.'];
        }

        $lines = [];

        foreach ($data['dishes'] as $dish) {
            $lines[] = sprintf('%s — %d', $dish['name'], $dish['quantity']);
        }

        $lines[] = '';
        $lines[] = sprintf('Разом позицій: %d · Учнів: %d', $data['positions'], $data['students']);

        return $lines;
    }

    public function telegramText(Supplier $supplier, CarbonInterface $date, array $data): string
    {
        $text = sprintf(
            "<b>Замовлення на %s</b>\n%s\n",
            $date->translatedFormat('d.m, l'),
            $supplier->name,
        );

        if ($data['positions'] === 0) {
            return $text."\nЗамовлень на цю дату немає.";
        }

        foreach ($data['dishes'] as $dish) {
            $text .= sprintf("\n• %s — <b>%d</b>", $dish['name'], $dish['quantity']);
        }

        $text .= sprintf("\n\nРазом позицій: <b>%d</b> · Учнів: <b>%d</b>", $data['positions'], $data['students']);

        return $text.$this->statusNote($data);
    }

    /** Попередження, що цифри ще можуть змінитися. */
    public function statusNote(array $data): string
    {
        if ($data['is_final']) {
            return '';
        }

        $until = $data['order_deadline']?->translatedFormat('d.m о H:i');

        return $until === null
            ? "\n\n⚠️ Приймання замовлень ще триває — цифри можуть змінитися."
            : "\n\n⚠️ Приймання триває до {$until} — цифри можуть змінитися. Після закриття надішлемо уточнене зведення.";
    }
}
